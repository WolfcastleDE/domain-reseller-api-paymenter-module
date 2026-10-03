<?php

namespace App\Helpers;

class ExtensionHelper
{
    public static function settingsToArray($settings)
    {
        if (is_array($settings)) {
            return $settings;
        }

        $result = [];
        foreach ($settings ?? [] as $setting) {
            $result[$setting->key] = $setting->value;
        }

        return $result;
    }
}
