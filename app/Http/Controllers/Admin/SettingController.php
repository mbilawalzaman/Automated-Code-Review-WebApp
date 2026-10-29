<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\SettingService;
use Illuminate\View\View;

class SettingController extends AdminController
{
    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    public function index(): View
    {
        return view('admin.settings.index', [
            'settings' => $this->settingService->getSettings(),
        ]);
    }
}
