<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class Regeneratethumbnails extends Module
{
    public function __construct()
    {
        $this->name = 'regeneratethumbnails';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Keraweb';
        $this->need_instance = 0;
        $this->bootstrap = false;

        parent::__construct();

        $this->displayName = $this->trans('RegenerateThumbnails', [], 'Modules.Regeneratethumbnails.Admin');
        $this->description = $this->trans('Provides an external CLI script to regenerate thumbnails without core overrides.', [], 'Modules.Regeneratethumbnails.Admin');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }
}
