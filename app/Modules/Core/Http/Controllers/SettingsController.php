<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Http\Requests\UpdateSettingsRequest;
use App\Modules\Core\Http\Resources\SettingResource;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Core\Settings\SettingsRegistry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SettingsRegistry $registry,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('settings.view');

        return $this->collection();
    }

    public function update(UpdateSettingsRequest $request): AnonymousResourceCollection
    {
        $this->settings->update($request->values());

        return $this->collection();
    }

    private function collection(): AnonymousResourceCollection
    {
        $values = $this->settings->all();
        $items = [];

        foreach ($this->registry->all() as $key => $definition) {
            $items[] = ['definition' => $definition, 'value' => $values[$key]];
        }

        return SettingResource::collection($items);
    }
}
