<?php

declare(strict_types=1);

namespace App\Xui;

/** Preserve JSON object/array types in 3x-ui xraySetting (avoid {} → []). */
final class XraySettingJson
{
    public static function decodeRoot(string $raw): object
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('قالب Xray خالی است');
        }
        $root = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($root)) {
            throw new \InvalidArgumentException('ریشه xraySetting باید JSON object باشد');
        }

        return $root;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rulesFromRoot(object $root): array
    {
        if (!isset($root->routing) || !is_object($root->routing)) {
            return [];
        }
        if (!isset($root->routing->rules) || !is_array($root->routing->rules)) {
            return [];
        }
        $rules = [];
        foreach ($root->routing->rules as $item) {
            if (is_object($item)) {
                $rules[] = self::objectToArray($item);
            } elseif (is_array($item)) {
                $rules[] = $item;
            }
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public static function configArrayForValidation(object $root): array
    {
        $json = json_encode($root, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [];
        }
        $arr = json_decode($json, true);

        return is_array($arr) ? $arr : [];
    }

    /**
     * Replace only routing.rules while preserving stdClass vs array types elsewhere.
     *
     * @param list<array<string, mixed>> $mergedRules
     */
    public static function replaceRoutingRules(string $originalRaw, array $mergedRules): string
    {
        $root = self::decodeRoot($originalRaw);
        $currentRules = self::rulesFromRoot($root);
        if (self::rulesListsEquivalent($currentRules, $mergedRules)) {
            return $originalRaw;
        }
        if (!isset($root->routing) || !is_object($root->routing)) {
            $root->routing = new \stdClass();
        }

        $root->routing->rules = self::buildRulesTreePreservingNodes($root, $mergedRules);

        $encoded = json_encode($root, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('سریال‌سازی xraySetting ناموفق بود');
        }

        $violation = self::assertPreservedOutsideRoutingRules($originalRaw, $encoded);
        if ($violation !== null) {
            throw new \RuntimeException($violation);
        }

        return $encoded;
    }

    public static function assertPreservedOutsideRoutingRules(string $originalRaw, string $newRaw): ?string
    {
        try {
            $before = self::decodeRoot($originalRaw);
            $after = self::decodeRoot($newRaw);
        } catch (\Throwable $e) {
            return 'JSON نامعتبر پس از جایگزینی routing.rules: ' . $e->getMessage();
        }

        self::stripRoutingRules($before);
        self::stripRoutingRules($after);

        return self::compareStructure($before, $after, '');
    }

    public static function configsStructurallyEqual(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        try {
            $oa = self::decodeRoot($a);
            $ob = self::decodeRoot($b);
        } catch (\Throwable) {
            return false;
        }

        return self::compareStructure($oa, $ob, '') === null;
    }

    public static function containsForbiddenEmptyArrayConversion(string $originalRaw, string $newRaw): bool
    {
        if (preg_match('/"headers"\s*:\s*\[\s*\]/', $newRaw) === 1
            && preg_match('/"headers"\s*:\s*\{\s*\}/', $originalRaw) === 1) {
            return true;
        }

        return false;
    }

    private static function stripRoutingRules(object $root): void
    {
        if (isset($root->routing) && is_object($root->routing)) {
            unset($root->routing->rules);
        }
    }

    private static function compareStructure(mixed $before, mixed $after, string $path): ?string
    {
        if ($before === $after) {
            return null;
        }

        $tb = gettype($before);
        $ta = gettype($after);
        if ($tb !== $ta) {
            if ($tb === 'object' && $ta === 'array') {
                return 'تبدیل object به array ممنوع است' . ($path !== '' ? " در {$path}" : '');
            }
            if ($tb === 'array' && $ta === 'object') {
                return 'تبدیل array به object ممنوع است' . ($path !== '' ? " در {$path}" : '');
            }

            return "نوع داده تغییر کرده ({$tb} → {$ta})" . ($path !== '' ? " در {$path}" : '');
        }

        if ($tb === 'object' && $before instanceof \stdClass && $after instanceof \stdClass) {
            $keys = array_unique(array_merge(array_keys(get_object_vars($before)), array_keys(get_object_vars($after))));
            sort($keys);
            foreach ($keys as $key) {
                $subPath = $path === '' ? (string) $key : $path . '.' . $key;
                $err = self::compareStructure($before->{$key} ?? null, $after->{$key} ?? null, $subPath);
                if ($err !== null) {
                    return $err;
                }
            }

            return null;
        }

        if ($tb === 'array') {
            if (count($before) !== count($after)) {
                return 'طول array تغییر کرده' . ($path !== '' ? " در {$path}" : '');
            }
            foreach ($before as $i => $bv) {
                $subPath = $path . '[' . $i . ']';
                $err = self::compareStructure($bv, $after[$i] ?? null, $subPath);
                if ($err !== null) {
                    return $err;
                }
            }

            return null;
        }

        if ($before !== $after) {
            return 'مقدار scalar تغییر کرده' . ($path !== '' ? " در {$path}" : '');
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $mergedRules
     * @return list<mixed>
     */
    private static function buildRulesTreePreservingNodes(object $root, array $mergedRules): array
    {
        $originalNodes = self::rulesNodesFromRoot($root);
        $originalArrays = [];
        foreach ($originalNodes as $node) {
            if (is_object($node)) {
                $originalArrays[] = self::objectToArray($node);
            } elseif (is_array($node)) {
                $originalArrays[] = $node;
            } else {
                $originalArrays[] = [];
            }
        }

        $used = [];
        $tree = [];
        foreach ($mergedRules as $merged) {
            $fp = XrayRoutingRules::fingerprint($merged);
            $node = null;
            foreach ($originalArrays as $idx => $arr) {
                if (isset($used[$idx])) {
                    continue;
                }
                if (XrayRoutingRules::fingerprint($arr) === $fp) {
                    $used[$idx] = true;
                    $node = self::deepCloneRuleNode($originalNodes[$idx]);
                    break;
                }
            }
            if ($node === null) {
                $rulesJson = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($rulesJson === false) {
                    throw new \RuntimeException('سریال‌سازی rule جدید ناموفق بود');
                }
                $decoded = json_decode($rulesJson, false);
                if (!is_object($decoded) && !is_array($decoded)) {
                    throw new \RuntimeException('rule جدید JSON نامعتبر است');
                }
                $node = $decoded;
            }
            $tree[] = $node;
        }

        return $tree;
    }

    /** @return list<mixed> */
    private static function rulesNodesFromRoot(object $root): array
    {
        if (!isset($root->routing) || !is_object($root->routing)) {
            return [];
        }
        if (!isset($root->routing->rules) || !is_array($root->routing->rules)) {
            return [];
        }

        return $root->routing->rules;
    }

    private static function deepCloneRuleNode(mixed $node): mixed
    {
        $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('clone rule node ناموفق بود');
        }

        return json_decode($json, false);
    }

    /**
     * @param list<array<string, mixed>> $a
     * @param list<array<string, mixed>> $b
     */
    private static function rulesListsEquivalent(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $rule) {
            if (!isset($b[$i]) || !is_array($b[$i])) {
                return false;
            }
            if (XrayRoutingRules::fingerprint($rule) !== XrayRoutingRules::fingerprint($b[$i])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private static function objectToArray(object $obj): array
    {
        $json = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [];
        }
        $arr = json_decode($json, true);

        return is_array($arr) ? $arr : [];
    }
}
