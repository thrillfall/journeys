<?php
declare(strict_types=1);

namespace OCA\Journeys\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('journeys', 'settings/personal', []);
    }

    public function getSection(): string {
        return 'journeys';
    }

    public function getPriority(): int {
        return 198;
    }
}
