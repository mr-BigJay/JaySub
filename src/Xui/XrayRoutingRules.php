<?php

declare(strict_types=1);

namespace App\Xui;

/** Parse, validate, and merge Xray routing.rules for 3x-ui v3.4.2 templates. */
final class XrayRoutingRules
{
    /**
     * @return array{config: array<string, mixed>, error?: string}
     */
    public static function parseConfig(string $xraySettingJson): array
    {
        $raw = trim($xraySettingJson);
        if ($raw === '') {
            return ['config' => [], 'error' => 'قالب Xray خالی است'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['config' => [], 'error' => 'JSON قالب Xray نامعتبر است'];
        }

        return ['config' => $decoded];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    public static function rulesFromConfig(array $config): array
    {
        $routing = $config['routing'] ?? null;
        if (!is_array($routing)) {
            return [];
        }
        $raw = $routing['rules'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $rules = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $rules[] = $item;
            }
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $rules
     * @return array<string, mixed>
     */
    public static function configWithRules(array $config, array $rules): array
    {
        if (!isset($config['routing']) || !is_array($config['routing'])) {
            $config['routing'] = [];
        }
        $items = [];
        foreach ($rules as $rule) {
            $items[] = $rule;
        }
        $config['routing']['rules'] = $items;

        return $config;
    }

    /** @param array<string, mixed> $rule */
    public static function isProtectedRoutingRule(array $rule): bool
    {
        $inbound = self::stringList($rule['inboundTag'] ?? null);
        $outbound = trim((string) ($rule['outboundTag'] ?? ''));
        foreach ($inbound as $tag) {
            $t = strtolower($tag);
            if ($t === 'api' || $t === 'metrics') {
                return true;
            }
        }
        if ($outbound === 'api' && $inbound !== []) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $allRules
     * @return array{protected: list<array<string, mixed>>, editable: list<array<string, mixed>>}
     */
    public static function splitProtectedRules(array $allRules): array
    {
        $protected = [];
        $editable = [];
        foreach ($allRules as $rule) {
            if (self::isProtectedRoutingRule($rule)) {
                $protected[] = $rule;
            } else {
                $editable[] = $rule;
            }
        }

        return ['protected' => $protected, 'editable' => $editable];
    }

    /**
     * @param list<array<string, mixed>> $protected
     * @param list<array<string, mixed>> $editable
     * @return list<array<string, mixed>>
     */
    public static function mergeRulesForSave(array $protected, array $editable): array
    {
        $editable = self::dedupeRules($editable);
        self::assertNoProtectedDuplicates($protected, $editable);

        return array_merge($protected, $editable);
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    public static function dedupeRules(array $rules): array
    {
        $seen = [];
        $out = [];
        foreach ($rules as $rule) {
            $fp = self::fingerprint($rule);
            if (isset($seen[$fp])) {
                continue;
            }
            $seen[$fp] = true;
            $out[] = $rule;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>> $rules
     * @return list<string>
     */
    public static function validateRulesForConfig(array $config, array $rules): array
    {
        $errors = [];
        $outTags = self::outboundTagsFromConfig($config);
        $balancerTags = self::balancerTagsFromConfig($config);
        $inboundTags = self::inboundTagsFromConfig($config);

        foreach ($rules as $i => $rule) {
            $idx = $i + 1;
            if (self::isProtectedRoutingRule($rule)) {
                continue;
            }
            $ob = trim((string) ($rule['outboundTag'] ?? ''));
            $bal = trim((string) ($rule['balancerTag'] ?? ''));
            if ($ob !== '' && $bal !== '') {
                $errors[] = "قانون {$idx}: outboundTag و balancerTag هم‌زمان مجاز نیست";
            }
            if ($ob !== '' && !in_array($ob, $outTags, true)) {
                $errors[] = "قانون {$idx}: outboundTag «{$ob}» در این پنل وجود ندارد";
            }
            if ($bal !== '' && !in_array($bal, $balancerTags, true)) {
                $errors[] = "قانون {$idx}: balancerTag «{$bal}» در این پنل وجود ندارد";
            }
            foreach (self::stringList($rule['inboundTag'] ?? null) as $tag) {
                if ($inboundTags !== [] && !in_array($tag, $inboundTags, true)) {
                    $errors[] = "قانون {$idx}: inboundTag «{$tag}» در این پنل یافت نشد";
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    public static function summarizeRule(array $rule, int $priority): array
    {
        $type = (string) ($rule['type'] ?? 'field');
        $domain = self::joinList($rule['domain'] ?? null);
        $ip = self::joinList($rule['ip'] ?? null);
        $inbound = self::joinList($rule['inboundTag'] ?? null);
        $outbound = trim((string) ($rule['outboundTag'] ?? ''));
        $balancer = trim((string) ($rule['balancerTag'] ?? ''));
        $dest = $balancer !== '' ? 'balancer:' . $balancer : ($outbound !== '' ? $outbound : '—');

        return [
            'priority' => $priority,
            'type' => $type,
            'domain' => $domain,
            'ip' => $ip,
            'inbound' => $inbound,
            'destination' => $dest,
            'protected' => self::isProtectedRoutingRule($rule),
            'fingerprint' => self::fingerprint($rule),
            'raw' => $rule,
        ];
    }

    /**
     * @param array<string, mixed> $match
     * @param array<string, mixed> $rule
     */
    public static function ruleMatchesSelector(array $match, array $rule): bool
    {
        if (($match['fingerprint'] ?? '') !== '' && self::fingerprint($rule) === $match['fingerprint']) {
            return true;
        }
        foreach (['domain', 'ip', 'outboundTag', 'balancerTag'] as $key) {
            if (!isset($match[$key]) || $match[$key] === '') {
                continue;
            }
            $want = (string) $match[$key];
            if ($key === 'domain' || $key === 'ip') {
                $hay = self::joinList($rule[$key] ?? null);
                if ($hay !== $want && !str_contains($hay, $want)) {
                    return false;
                }
            } else {
                if (trim((string) ($rule[$key] ?? '')) !== $want) {
                    return false;
                }
            }
        }
        if (isset($match['inboundTag']) && $match['inboundTag'] !== '') {
            $tags = self::stringList($rule['inboundTag'] ?? null);
            if (!in_array((string) $match['inboundTag'], $tags, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $editable
     * @param array<string, mixed> $match
     */
    public static function findEditableIndex(array $editable, array $match): ?int
    {
        foreach ($editable as $i => $rule) {
            if (self::isProtectedRoutingRule($rule)) {
                continue;
            }
            if (self::ruleMatchesSelector($match, $rule)) {
                return $i;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $config */
    public static function encodeConfig(array $config): string
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('سریال‌سازی Xray JSON ناموفق بود');
        }

        return $json;
    }

    /** @param array<string, mixed> $rule */
    public static function fingerprint(array $rule): string
    {
        $copy = $rule;
        unset($copy['_jaySubIndex']);
        $json = json_encode(self::normalizeForFingerprint($copy), JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS);

        return hash('sha256', $json !== false ? $json : '');
    }

    /** @param array<string, mixed> $config @return list<string> */
    public static function outboundTagsFromConfig(array $config): array
    {
        $tags = [];
        $outbounds = $config['outbounds'] ?? [];
        if (!is_array($outbounds)) {
            return [];
        }
        foreach ($outbounds as $ob) {
            if (is_array($ob) && isset($ob['tag']) && is_string($ob['tag'])) {
                $tags[] = $ob['tag'];
            }
        }

        return $tags;
    }

    /** @param array<string, mixed> $config @return list<string> */
    public static function balancerTagsFromConfig(array $config): array
    {
        $tags = [];
        $routing = $config['routing'] ?? null;
        if (!is_array($routing)) {
            return [];
        }
        $balancers = $routing['balancers'] ?? [];
        if (!is_array($balancers)) {
            return [];
        }
        foreach ($balancers as $b) {
            if (is_array($b) && isset($b['tag']) && is_string($b['tag'])) {
                $tags[] = $b['tag'];
            }
        }

        return $tags;
    }

    /** @param array<string, mixed> $config @return list<string> */
    public static function inboundTagsFromConfig(array $config): array
    {
        $tags = [];
        $inbounds = $config['inbounds'] ?? [];
        if (!is_array($inbounds)) {
            return [];
        }
        foreach ($inbounds as $in) {
            if (is_array($in) && isset($in['tag']) && is_string($in['tag'])) {
                $tags[] = $in['tag'];
            }
        }

        return $tags;
    }

    /** @param mixed $value @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** @param mixed $value */
    private static function joinList(mixed $value): string
    {
        $list = self::stringList($value);

        return $list === [] ? '—' : implode(', ', $list);
    }

    /**
     * @param list<array<string, mixed>> $protected
     * @param list<array<string, mixed>> $editable
     */
    private static function assertNoProtectedDuplicates(array $protected, array $editable): void
    {
        $protectedFp = [];
        foreach ($protected as $rule) {
            $protectedFp[self::fingerprint($rule)] = true;
        }
        foreach ($editable as $rule) {
            if (isset($protectedFp[self::fingerprint($rule)])) {
                throw new \InvalidArgumentException('قانون تکراری با قوانین محافظت‌شده API/آمار');
            }
        }
    }

    /** @param mixed $data @return mixed */
    private static function normalizeForFingerprint(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        ksort($data);
        foreach ($data as $k => $v) {
            $data[$k] = self::normalizeForFingerprint($v);
        }

        return $data;
    }
}
