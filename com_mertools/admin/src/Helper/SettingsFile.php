<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_mertools
 *
 * The settings of MerTools in a file, to keep them or to take them to another site: every setting of
 * the plugin's manifest with its value (the default where it was never saved), as JSON. Not in it: the
 * panels (they show, they keep nothing), notes, and secrets (the PageSpeed key is the site's own). Read
 * back, only the settings this version knows are taken, each as a plain value or a list of them.
 */

namespace Merserwis\Component\MerTools\Administrator\Helper;

\defined('_JEXEC') or die;

final class SettingsFile
{
    public const FORMAT = 'mertools-settings';

    /** a file of settings is a few KB; anything much bigger is not one */
    public const MAX_BYTES = 1048576;

    /** the fields of MerTools' own types that hold a setting (the other ones are panels) */
    private const VALUE_TYPES = ['mtapps', 'mtfeedapps', 'mtproductfields'];

    /**
     * The settings of the manifest: name => [page, default, secret, multiple].
     *
     * @return array<string, array{page: string, default: ?string, secret: bool, multiple: bool}>
     */
    public static function fields(\SimpleXMLElement $config): array
    {
        $out = [];
        foreach ($config->fields->fieldset ?? [] as $fieldset) {
            foreach ($fieldset->field as $field) {
                $type = strtolower((string) $field['type']);
                $name = (string) $field['name'];
                if ($name === '' || \in_array($type, ['note', 'spacer'], true)
                    || (str_starts_with($type, 'mt') && !isset($field['default']) && !\in_array($type, self::VALUE_TYPES, true))) {
                    continue;
                }
                $out[$name] = ['page' => (string) $fieldset['page'], 'default' => isset($field['default']) ? (string) $field['default'] : null,
                    'secret' => $type === 'password', 'multiple' => \in_array(strtolower((string) $field['multiple']), ['true', 'multiple', '1'], true)];
            }
        }

        return $out;
    }

    /**
     * The file: format, version, site and time, then every setting (saved value, else its default, else
     * empty — a list of apps never chosen is an empty list, so importing the file empties it again).
     *
     * @param array<string, mixed> $params the plugin's parameters
     * @param array<string, array> $fields self::fields()
     */
    public static function export(array $params, array $fields, string $version, string $site, string $when): string
    {
        $settings = [];
        foreach ($fields as $name => $field) {
            if ($field['secret']) {
                continue;
            }
            if (\array_key_exists($name, $params)) {
                $settings[$name] = $params[$name];
            } else {
                $settings[$name] = $field['default'] ?? ($field['multiple'] ? [] : '');
            }
        }
        ksort($settings);

        return json_encode(['format' => self::FORMAT, 'version' => $version, 'site' => $site, 'exported' => $when, 'settings' => $settings],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * A file read: its settings, or why it is not a file of MerTools' settings.
     *
     * @return array{settings: array<string, mixed>, version: string, site: string, error: ?string}
     */
    public static function read(string $json): array
    {
        $none = ['settings' => [], 'version' => '', 'site' => '', 'error' => null];
        if (\strlen($json) > self::MAX_BYTES) {
            return ['error' => 'size'] + $none;
        }
        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json, true);
        if (!\is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !\is_array($data['settings'] ?? null)) {
            return ['error' => 'format'] + $none;
        }

        return ['settings' => $data['settings'], 'version' => (string) ($data['version'] ?? ''), 'site' => (string) ($data['site'] ?? ''), 'error' => null];
    }

    /**
     * The settings of a file this version takes: known, not secret, a plain value or a list of plain values.
     *
     * @param array<string, mixed> $settings
     * @param array<string, array> $fields self::fields()
     *
     * @return array{take: array<string, mixed>, unknown: string[], bad: string[]}
     */
    public static function pick(array $settings, array $fields): array
    {
        $take    = [];
        $unknown = [];
        $bad     = [];
        foreach ($settings as $name => $value) {
            $name = (string) $name;
            if (!isset($fields[$name]) || $fields[$name]['secret'] || $fields[$name]['page'] === '') {
                $unknown[] = $name;
            } elseif (\is_scalar($value) || $value === null) {
                $take[$name] = $value === null ? '' : (\is_bool($value) ? (int) $value : $value);
            } elseif (\is_array($value) && array_is_list($value) && array_filter($value, static fn ($v) => !\is_scalar($v)) === []) {
                $take[$name] = $value;
            } else {
                $bad[] = $name;
            }
        }

        return ['take' => $take, 'unknown' => $unknown, 'bad' => $bad];
    }
}
