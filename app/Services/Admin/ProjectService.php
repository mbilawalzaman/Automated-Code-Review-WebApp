<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditEventType;
use App\Enums\AuditSeverity;
use App\Models\Project;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ProjectService
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projectRepository,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return Collection<int, Project>
     */
    public function list(): Collection
    {
        return $this->projectRepository->all();
    }

    /**
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        return $this->projectRepository->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Project
    {
        if (! isset($data['slug']) && isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $data['created_by'] ??= auth()->id();

        $project = $this->projectRepository->create($data);

        $this->auditLogService->record(
            category: AuditCategory::Project,
            eventType: AuditEventType::Created,
            action: "Project created: {$project->name}",
            severity: AuditSeverity::Info,
            target: $project,
            afterState: ['name' => $project->name, 'slug' => $project->slug],
            reason: 'New project creation'
        );

        return $project;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): Project
    {
        $project = $this->findOrFail($id);
        $beforeState = ['name' => $project->name, 'slug' => $project->slug, 'description' => $project->description];

        if (isset($data['name']) && ! isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $updated = $this->projectRepository->update($project, $data);
        $afterState = ['name' => $updated->name, 'slug' => $updated->slug, 'description' => $updated->description];

        $this->auditLogService->record(
            category: AuditCategory::Project,
            eventType: AuditEventType::Updated,
            action: "Project updated: {$updated->name}",
            severity: AuditSeverity::Info,
            target: $updated,
            beforeState: $beforeState,
            afterState: $afterState,
            reason: 'Project settings update'
        );

        return $updated;
    }

    public function delete(int $id): void
    {
        $project = $this->findOrFail($id);
        $beforeState = ['name' => $project->name, 'slug' => $project->slug];

        $this->projectRepository->delete($project);

        $this->auditLogService->record(
            category: AuditCategory::Project,
            eventType: AuditEventType::Deleted,
            action: "Project deleted: {$project->name}",
            severity: AuditSeverity::Warning,
            target: $project,
            beforeState: $beforeState,
            reason: 'Project deletion'
        );
    }

    public function findOrFail(int $id): Project
    {
        $project = $this->projectRepository->findById($id);

        if ($project === null) {
            abort(404);
        }

        return $project;
    }
}
