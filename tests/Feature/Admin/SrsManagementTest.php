<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\SrsDocumentStatus;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Repository;
use App\Models\SrsDocument;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SrsManagementTest extends TestCase
{
    protected Project $project;

    protected Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->project = Project::factory()->create();
        $this->repository = Repository::factory()->create([
            'project_id' => $this->project->id,
            'full_name' => 'acme/srs-repo',
        ]);
    }

    protected function srsDocumentContent(): string
    {
        return <<<'MD'
        # Requirements

        SEC-001 The system shall reject secrets committed to the repository.
        SEC-002 The system should warn when dependencies are outdated.
        3.1. The system shall record an audit entry for every override.
        Some background prose that is not a requirement at all.
        MD;
    }

    protected function uploadRevision(User $actor, ?string $contents = null): SrsDocument
    {
        $file = UploadedFile::fake()->createWithContent('srs.md', $contents ?? $this->srsDocumentContent());

        $this->actingAs($actor)->post(route('admin.srs.store'), [
            'source_type' => 'upload',
            'title' => 'Acme SRS',
            'project_id' => $this->project->id,
            'repository_id' => $this->repository->id,
            'document' => $file,
        ])->assertRedirect();

        return SrsDocument::query()->latest('id')->firstOrFail();
    }

    public function test_upload_creates_draft_revision_and_extracts_requirements(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $document = $this->uploadRevision($manager);

        $this->assertSame(SrsDocumentStatus::Draft, $document->status);
        $this->assertSame(1, $document->version);
        $this->assertSame('upload', $document->source_type);
        $this->assertNotEmpty($document->content_hash);

        $objectives = $document->objectives()->orderBy('sort_order')->get();
        $this->assertCount(3, $objectives);
        $this->assertSame('SEC-001', $objectives[0]->code);
        $this->assertTrue((bool) $objectives[0]->is_mandatory);
        $this->assertFalse((bool) $objectives[1]->is_mandatory, 'A "should" requirement is advisory, not mandatory.');
        $this->assertFalse((bool) $objectives[0]->is_approved, 'Extracted requirements must not be active before approval.');

        $this->assertDatabaseHas('audit_logs', [
            'category' => AuditCategory::Srs->value,
            'event_type' => AuditEventType::Created->value,
            'target_type' => SrsDocument::class,
            'target_id' => $document->id,
        ]);
    }

    public function test_github_source_fetches_document_through_api(): void
    {
        config(['github.token' => 'gh_test_token']);

        Http::fake([
            'api.github.com/repos/acme/srs-repo/contents/docs/SRS.md*' => Http::response([
                'type' => 'file',
                'name' => 'SRS.md',
                'html_url' => 'https://github.com/acme/srs-repo/blob/main/docs/SRS.md',
                'content' => base64_encode($this->srsDocumentContent()),
            ]),
        ]);

        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $this->actingAs($manager)->post(route('admin.srs.store'), [
            'source_type' => 'github',
            'title' => 'Acme SRS from GitHub',
            'project_id' => $this->project->id,
            'repository_id' => $this->repository->id,
            'reference' => 'docs/SRS.md',
        ])->assertRedirect();

        $document = SrsDocument::query()->latest('id')->firstOrFail();

        $this->assertSame('github', $document->source_type);
        $this->assertSame('https://github.com/acme/srs-repo/blob/main/docs/SRS.md', $document->source_url);
        $this->assertSame(3, $document->objectives()->count());
    }

    public function test_google_drive_source_is_hidden_until_configured(): void
    {
        config(['services.google_drive.service_account' => null, 'services.google_drive.folder_id' => null]);

        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $this->actingAs($manager)->post(route('admin.srs.store'), [
            'source_type' => 'google_drive',
            'title' => 'Drive SRS',
            'project_id' => $this->project->id,
            'repository_id' => $this->repository->id,
            'reference' => 'some-file-id',
        ])->assertSessionHasErrors('source_type');

        $this->assertSame(0, SrsDocument::query()->count());
    }

    public function test_checklist_can_be_edited_before_approval(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);
        $objectives = $document->objectives()->orderBy('sort_order')->get();

        $this->actingAs($manager)->put(route('admin.srs.checklist.update', $document), [
            'items' => [
                ['id' => $objectives[0]->id, 'code' => 'SEC-001', 'title' => 'Reject committed secrets', 'is_mandatory' => '1'],
                ['id' => $objectives[1]->id, 'remove' => '1'],
                ['id' => $objectives[2]->id, 'code' => 'AUD-001', 'title' => 'Audit every override', 'is_mandatory' => '1'],
                ['code' => 'MAN-001', 'title' => 'Manually added requirement', 'is_mandatory' => '0'],
            ],
        ])->assertRedirect();

        $document->refresh();
        $codes = $document->objectives()->orderBy('sort_order')->pluck('code')->all();

        $this->assertSame(['SEC-001', 'AUD-001', 'MAN-001'], $codes);
        $this->assertSame('Reject committed secrets', $document->objectives()->where('code', 'SEC-001')->value('title'));
        $this->assertTrue((bool) $document->objectives()->where('code', 'AUD-001')->value('is_mandatory'));
        $this->assertFalse((bool) $document->objectives()->where('code', 'MAN-001')->value('is_mandatory'));
    }

    public function test_blank_requirement_codes_are_autogenerated_on_checklist_save(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);
        $objectives = $document->objectives()->orderBy('sort_order')->get();

        $this->actingAs($manager)->put(route('admin.srs.checklist.update', $document), [
            'items' => [
                ['id' => $objectives[0]->id, 'code' => 'REQ-001', 'title' => $objectives[0]->title, 'is_mandatory' => '1'],
                ['id' => $objectives[1]->id, 'code' => 'REQ-002', 'title' => $objectives[1]->title, 'is_mandatory' => '0'],
                ['id' => $objectives[2]->id, 'code' => '', 'title' => 'Requirement with empty code', 'is_mandatory' => '1'],
                ['code' => 'REQ-NEW', 'title' => 'New requirement with REQ-NEW code', 'is_mandatory' => '0'],
            ],
        ])->assertRedirect();

        $document->refresh();
        $codes = $document->objectives()->orderBy('sort_order')->pluck('code')->all();

        $this->assertSame(['REQ-001', 'REQ-002', 'REQ-003', 'REQ-004'], $codes);
    }

    public function test_duplicate_requirement_codes_are_rejected_on_checklist_save(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);
        $objectives = $document->objectives()->orderBy('sort_order')->get();
        $beforeCount = $document->objectives()->count();

        $response = $this->actingAs($manager)->from(route('admin.srs.show', $document))->put(route('admin.srs.checklist.update', $document), [
            'items' => [
                ['id' => $objectives[0]->id, 'code' => 'SEC-001', 'title' => $objectives[0]->title, 'is_mandatory' => '1'],
                ['id' => $objectives[1]->id, 'code' => $objectives[1]->code, 'title' => $objectives[1]->title, 'is_mandatory' => '0'],
                ['id' => $objectives[2]->id, 'code' => $objectives[2]->code, 'title' => $objectives[2]->title, 'is_mandatory' => '1'],
                ['code' => 'sec-001', 'title' => 'Duplicate code should fail', 'is_mandatory' => '0'],
            ],
        ]);

        $response->assertRedirect(route('admin.srs.show', $document));
        $response->assertSessionHasErrors('checklist');
        $this->assertStringContainsString('Duplicate requirement code', (string) session('errors')->first('checklist'));
        $this->assertSame($beforeCount, $document->objectives()->count());
        $this->assertSame(1, $document->objectives()->where('code', 'SEC-001')->count());
    }

    public function test_checklist_title_max_length_shows_friendly_inline_error(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);
        $objectives = $document->objectives()->orderBy('sort_order')->get();
        $beforeCount = $document->objectives()->count();

        $response = $this->actingAs($manager)->from(route('admin.srs.show', $document))->put(route('admin.srs.checklist.update', $document), [
            'items' => [
                ['id' => $objectives[0]->id, 'code' => $objectives[0]->code, 'title' => $objectives[0]->title, 'is_mandatory' => '1'],
                ['id' => $objectives[1]->id, 'code' => $objectives[1]->code, 'title' => $objectives[1]->title, 'is_mandatory' => '0'],
                ['id' => $objectives[2]->id, 'code' => $objectives[2]->code, 'title' => $objectives[2]->title, 'is_mandatory' => '1'],
                'new' => ['code' => 'REQ-NEW', 'title' => str_repeat('a', 256), 'is_mandatory' => '0'],
            ],
        ]);

        $response->assertRedirect(route('admin.srs.show', $document));
        $response->assertSessionHasErrors('items.new.title');
        $this->assertSame(
            'Requirement text must be 255 characters or fewer.',
            session('errors')->getBag('default')->first('items.new.title')
        );
        $this->assertSame($beforeCount, $document->objectives()->count());
    }

    public function test_checklist_description_max_length_shows_friendly_inline_error(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);
        $objectives = $document->objectives()->orderBy('sort_order')->get();

        $response = $this->actingAs($manager)->from(route('admin.srs.show', $document))->put(route('admin.srs.checklist.update', $document), [
            'items' => [
                [
                    'id' => $objectives[0]->id,
                    'code' => $objectives[0]->code,
                    'title' => $objectives[0]->title,
                    'description' => str_repeat('d', 2001),
                    'is_mandatory' => '1',
                ],
                ['id' => $objectives[1]->id, 'code' => $objectives[1]->code, 'title' => $objectives[1]->title, 'is_mandatory' => '0'],
                ['id' => $objectives[2]->id, 'code' => $objectives[2]->code, 'title' => $objectives[2]->title, 'is_mandatory' => '1'],
            ],
        ]);

        $response->assertRedirect(route('admin.srs.show', $document));
        $response->assertSessionHasErrors('items.0.description');
        $this->assertSame(
            'Requirement description must be 2,000 characters or fewer.',
            session('errors')->getBag('default')->first('items.0.description')
        );
    }

    public function test_approval_requires_reauthentication_and_activates_checklist(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);

        $this->actingAs($manager)->post(route('admin.srs.submit', $document))->assertRedirect();
        $this->assertSame(SrsDocumentStatus::PendingApproval, $document->fresh()->status);

        $this->actingAs($manager)
            ->post(route('admin.srs.approve', $document))
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(SrsDocumentStatus::PendingApproval, $document->fresh()->status);

        $this->actingAs($manager)
            ->withConfirmedPassword()
            ->post(route('admin.srs.approve', $document))
            ->assertRedirect();

        $document->refresh();
        $this->assertSame(SrsDocumentStatus::Approved, $document->status);
        $this->assertSame($manager->id, $document->approved_by);
        $this->assertSame(3, $document->objectives()->where('is_approved', true)->count());
        $this->assertNotEmpty($document->checklist);

        $this->assertDatabaseHas('audit_logs', [
            'category' => AuditCategory::Srs->value,
            'event_type' => AuditEventType::Approved->value,
            'target_id' => $document->id,
        ]);
    }

    public function test_approved_revision_is_superseded_by_the_next_approved_revision(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $first = $this->uploadRevision($manager);
        $this->actingAs($manager)->post(route('admin.srs.submit', $first));
        $this->actingAs($manager)->withConfirmedPassword()->post(route('admin.srs.approve', $first));

        $second = $this->uploadRevision($manager, "REQ-100 The system shall enforce branch protection.\n");
        $this->assertSame(2, $second->version);

        $this->actingAs($manager)->post(route('admin.srs.submit', $second));
        $this->actingAs($manager)->withConfirmedPassword()->post(route('admin.srs.approve', $second));

        $first->refresh();
        $this->assertSame(SrsDocumentStatus::Superseded, $first->status);
        $this->assertNotNull($first->superseded_at);
        $this->assertSame(0, $first->objectives()->where('is_approved', true)->count());

        $this->assertSame(1, SrsDocument::query()->activeApproved()->count());
    }

    public function test_approved_revision_cannot_be_edited(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);

        $this->actingAs($manager)->post(route('admin.srs.submit', $document));
        $this->actingAs($manager)->withConfirmedPassword()->post(route('admin.srs.approve', $document));

        $objective = $document->objectives()->firstOrFail();

        $this->actingAs($manager)->put(route('admin.srs.checklist.update', $document), [
            'items' => [['id' => $objective->id, 'title' => 'Tampered requirement', 'is_mandatory' => '0']],
        ])->assertSessionHasErrors('checklist');

        $this->assertNotSame('Tampered requirement', $document->objectives()->where('id', $objective->id)->value('title'));
    }

    public function test_rejection_records_reason_and_reopens_editing(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);

        $this->actingAs($manager)->post(route('admin.srs.submit', $document));

        $this->actingAs($manager)->withConfirmedPassword()->post(route('admin.srs.reject', $document), [
            'reason' => 'Requirements are missing the performance section.',
        ])->assertRedirect();

        $document->refresh();
        $this->assertSame(SrsDocumentStatus::Rejected, $document->status);
        $this->assertSame('Requirements are missing the performance section.', $document->rejection_reason);
        $this->assertTrue($document->isEditable());
    }

    public function test_submission_requires_at_least_one_requirement(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);
        Objective::query()->where('srs_document_id', $document->id)->delete();

        $this->actingAs($manager)->post(route('admin.srs.submit', $document))
            ->assertSessionHasErrors('checklist');

        $this->assertSame(SrsDocumentStatus::Draft, $document->fresh()->status);
    }

    public function test_documents_without_readable_text_are_rejected(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $this->actingAs($manager)->post(route('admin.srs.store'), [
            'source_type' => 'upload',
            'title' => 'Broken SRS',
            'project_id' => $this->project->id,
            'repository_id' => $this->repository->id,
            'document' => UploadedFile::fake()->createWithContent('srs.md', '   '),
        ])->assertSessionHasErrors('document');

        $this->assertSame(0, SrsDocument::query()->count());
    }

    public function test_unsupported_upload_type_error_is_cleared_from_create_form_script(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $this->actingAs($manager)->from(route('admin.srs.index'))->post(route('admin.srs.store'), [
            'source_type' => 'upload',
            'title' => 'Image SRS',
            'project_id' => $this->project->id,
            'repository_id' => $this->repository->id,
            'document' => UploadedFile::fake()->create('diagram.png', 20, 'image/png'),
        ])->assertRedirect(route('admin.srs.index'))
            ->assertSessionHasErrors('document');

        $this->assertStringContainsString(
            'Unsupported file type',
            (string) session('errors')->getBag('default')->first('document')
        );

        $this->actingAs($manager)
            ->get(route('admin.srs.index'))
            ->assertOk()
            ->assertSee('onDocumentChange', false)
            ->assertSee('Unsupported file type. Upload Markdown, TXT, PDF, or DOCX.', false);

        $this->assertSame(0, SrsDocument::query()->count());
    }

    public function test_large_text_upload_is_accepted_without_exposing_sql_errors(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        // Larger than MySQL TEXT (64KB) but within the 10 MB upload limit.
        $body = "# Requirements\n\nSEC-001 The system shall accept large requirement documents.\n\n"
            .str_repeat("Background detail for capacity planning. ", 4000);

        $this->assertGreaterThan(65_535, strlen($body));

        $this->actingAs($manager)->post(route('admin.srs.store'), [
            'source_type' => 'upload',
            'title' => 'Large SRS',
            'project_id' => $this->project->id,
            'repository_id' => $this->repository->id,
            'document' => UploadedFile::fake()->createWithContent('large-srs.txt', $body),
        ])->assertRedirect();

        $document = SrsDocument::query()->latest('id')->firstOrFail();
        $this->assertSame('Large SRS', $document->title);
        $this->assertGreaterThan(65_535, strlen((string) $document->extracted_text));
        $this->assertStringNotContainsString('SQLSTATE', (string) session('errors')?->first('document'));
    }

    public function test_draft_revision_can_be_deleted(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);
        $objectiveIds = $document->objectives()->pluck('id')->all();

        $this->actingAs($manager)
            ->delete(route('admin.srs.destroy', $document))
            ->assertRedirect(route('admin.srs.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('srs_documents', ['id' => $document->id]);
        $this->assertSame(0, Objective::query()->whereIn('id', $objectiveIds)->count());
    }

    public function test_approved_revision_cannot_be_deleted(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);

        $this->actingAs($manager)->post(route('admin.srs.submit', $document));
        $this->actingAs($manager)->withConfirmedPassword()->post(route('admin.srs.approve', $document));

        $this->actingAs($manager)
            ->from(route('admin.srs.show', $document))
            ->delete(route('admin.srs.destroy', $document))
            ->assertRedirect(route('admin.srs.show', $document))
            ->assertSessionHasErrors('checklist');

        $this->assertDatabaseHas('srs_documents', [
            'id' => $document->id,
            'status' => SrsDocumentStatus::Approved->value,
        ]);
    }

    public function test_draft_document_can_be_replaced_and_reextracted(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);
        $originalHash = $document->content_hash;
        $originalCount = $document->objectives()->count();

        $replacement = <<<'MD'
        # Requirements

        AUTH-001 The system shall require multi-factor authentication for admin users.
        AUTH-002 The system should expire inactive sessions.
        MD;

        $this->actingAs($manager)->post(route('admin.srs.replace', $document), [
            'source_type' => 'upload',
            'title' => 'Replaced SRS',
            'document' => UploadedFile::fake()->createWithContent('replacement.md', $replacement),
        ])->assertRedirect()
            ->assertSessionHas('status');

        $document->refresh();
        $this->assertSame('Replaced SRS', $document->title);
        $this->assertSame('replacement.md', $document->original_filename);
        $this->assertNotSame($originalHash, $document->content_hash);
        $this->assertNotSame($originalCount, $document->objectives()->count());
        $this->assertSame(['AUTH-001', 'AUTH-002'], $document->objectives()->orderBy('sort_order')->pluck('code')->all());
    }

    public function test_drive_folder_link_is_reported_on_the_reference_field(): void
    {
        config([
            'services.google_drive.service_account' => '{"client_email":"reader@example.iam.gserviceaccount.com","private_key":"key"}',
            'services.google_drive.folder_id' => 'folder-abc-1234567890',
        ]);

        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $this->actingAs($manager)->post(route('admin.srs.store'), [
            'source_type' => 'google_drive',
            'title' => 'Drive SRS',
            'project_id' => $this->project->id,
            'repository_id' => $this->repository->id,
            'reference' => 'https://drive.google.com/drive/folders/1ke4VbKUS3cLXUaK8wP4liZmQOG0lPH',
        ])->assertSessionHasErrors('reference');

        $this->assertSame(0, SrsDocument::query()->count());
    }

    public function test_project_wide_revision_can_be_created_without_repository(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $file = UploadedFile::fake()->createWithContent('srs.md', $this->srsDocumentContent());

        $this->actingAs($manager)->post(route('admin.srs.store'), [
            'source_type' => 'upload',
            'title' => 'Project Wide SRS',
            'project_id' => $this->project->id,
            'repository_id' => null,
            'document' => $file,
        ])->assertRedirect();

        $document = SrsDocument::query()->latest('id')->firstOrFail();

        $this->assertSame($this->project->id, $document->project_id);
        $this->assertNull($document->repository_id);
        $this->assertSame(1, $document->version);
    }

    public function test_repository_must_belong_to_selected_project(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $otherProject = Project::factory()->create();
        $file = UploadedFile::fake()->createWithContent('srs.md', $this->srsDocumentContent());

        $this->actingAs($manager)->post(route('admin.srs.store'), [
            'source_type' => 'upload',
            'title' => 'Mismatched Scope SRS',
            'project_id' => $otherProject->id,
            'repository_id' => $this->repository->id,
            'document' => $file,
        ])->assertSessionHasErrors('repository_id');

        $this->assertSame(0, SrsDocument::query()->count());
    }

    public function test_create_modal_lists_project_before_repository(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        $html = $this->actingAs($manager)
            ->get(route('admin.srs.index'))
            ->assertOk()
            ->getContent();

        $projectPos = strpos($html, 'id="srs-project"');
        $repositoryPos = strpos($html, 'id="srs-repository"');

        $this->assertNotFalse($projectPos);
        $this->assertNotFalse($repositoryPos);
        $this->assertLessThan($repositoryPos, $projectPos);
        $this->assertStringContainsString('srsCreateForm', $html);
        $this->assertStringContainsString('filteredRepositories', $html);
        // JSON must not be inlined into a quoted x-data attribute (breaks Alpine / leaks raw JS).
        $this->assertStringNotContainsString('repositories: [{"id"', $html);
    }

    public function test_index_filters_by_project(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $mine = $this->uploadRevision($manager);

        $otherProject = Project::factory()->create(['name' => 'Unrelated Project']);
        $otherRepository = Repository::factory()->create([
            'project_id' => $otherProject->id,
            'full_name' => 'acme/other-repo',
        ]);
        $other = SrsDocument::query()->create([
            'project_id' => $otherProject->id,
            'repository_id' => $otherRepository->id,
            'title' => 'Unrelated SRS',
            'version' => 1,
            'source_type' => 'upload',
        ]);

        $this->actingAs($manager)
            ->get(route('admin.srs.index', ['project_id' => $this->project->id]))
            ->assertOk()
            ->assertSee($mine->title)
            ->assertDontSee($other->title);
    }

    public function test_index_shows_admin_pagination_controls_and_respects_per_page(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        for ($i = 1; $i <= 11; $i++) {
            SrsDocument::query()->create([
                'project_id' => $this->project->id,
                'repository_id' => $this->repository->id,
                'title' => sprintf('SRS PageItem %02d', $i),
                'version' => $i,
                'source_type' => 'upload',
            ]);
        }

        $this->actingAs($manager)
            ->get(route('admin.srs.index'))
            ->assertOk()
            ->assertSee('Showing', false)
            ->assertSee('entries', false)
            ->assertSee('Previous', false)
            ->assertSee('Next', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('SRS PageItem 11')
            ->assertDontSee('SRS PageItem 01');

        $this->actingAs($manager)
            ->get(route('admin.srs.index', ['per_page' => 25]))
            ->assertOk()
            ->assertSee('SRS PageItem 01')
            ->assertSee('SRS PageItem 11');
    }

    public function test_index_repository_filter_includes_project_wide_revisions(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);

        // Scoped to the whole project rather than to a single repository.
        $projectWide = SrsDocument::query()->create([
            'project_id' => $this->project->id,
            'repository_id' => null,
            'title' => 'Project Wide SRS',
            'version' => 5,
            'source_type' => 'upload',
        ]);

        $repositoryScoped = $this->uploadRevision($manager);

        $unrelatedProject = Project::factory()->create();
        $unrelated = SrsDocument::query()->create([
            'project_id' => $unrelatedProject->id,
            'repository_id' => null,
            'title' => 'Other Project Wide SRS',
            'version' => 1,
            'source_type' => 'upload',
        ]);

        $this->actingAs($manager)
            ->get(route('admin.srs.index', ['repository_id' => $this->repository->id]))
            ->assertOk()
            ->assertSee($repositoryScoped->title)
            ->assertSee($projectWide->title)
            ->assertDontSee($unrelated->title);
    }

    public function test_index_repository_filter_ignores_unknown_repository(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);

        $this->actingAs($manager)
            ->get(route('admin.srs.index', ['repository_id' => 999999]))
            ->assertOk()
            ->assertDontSee($document->title);
    }

    public function test_users_with_manage_permission_can_see_add_revision_button(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['email_verified_at' => now()]);

        $this->actingAs($superAdmin)
            ->get(route('admin.srs.index'))
            ->assertOk()
            ->assertSee('Add SRS Revision');
    }

    public function test_security_auditor_can_view_but_not_manage_srs(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);

        $auditor = User::factory()->securityAuditor()->create(['email_verified_at' => now()]);

        $this->actingAs($auditor)
            ->get(route('admin.srs.index'))
            ->assertOk()
            ->assertDontSee('Add SRS Revision');
        $this->actingAs($auditor)->get(route('admin.srs.show', $document))->assertOk();
        $this->actingAs($auditor)->post(route('admin.srs.submit', $document))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
        $this->actingAs($auditor)->withConfirmedPassword()
            ->post(route('admin.srs.approve', $document))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
    }

    public function test_developer_cannot_access_srs_module(): void
    {
        $developer = User::factory()->developer()->create(['email_verified_at' => now()]);

        $this->actingAs($developer)->get(route('admin.srs.index'))->assertForbidden();
    }

    public function test_checklist_fields_are_disabled_and_styled_when_srs_is_pending_approval_or_approved(): void
    {
        $manager = User::factory()->projectManager()->create(['email_verified_at' => now()]);
        $document = $this->uploadRevision($manager);

        // Submit for approval -> Pending Approval
        $this->actingAs($manager)->post(route('admin.srs.submit', $document))->assertRedirect();
        $document->refresh();

        $response = $this->actingAs($manager)->get(route('admin.srs.show', $document));
        $response->assertOk()
            ->assertSee('disabled:bg-gray-100', false)
            ->assertSee('disabled:cursor-not-allowed', false)
            ->assertSee('unsaved-changes-alert', false)
            ->assertSee('srsChecklistGuard', false);
    }
}
