<?php

declare(strict_types=1);

namespace App\Xui;

/** Parse and classify xraySetting.outbounds (3x-ui v3.4.2 template). */
final class XrayOutbounds
{
    /** @var list<string> */
    public const PROTECTED_TAGS = ['direct', 'block', 'api'];

    /**
     * Template outbounds from xraySetting only (excludes tags owned by subscriptionOutboundTags).
     *
     * @param list<string> $subscriptionTagsFromApi
     * @return list<array<string, mixed>>
     */
    public static function summarizeTemplateRoot(object $root, array $subscriptionTagsFromApi = []): array
    {
        $subs = array_fill_keys($subscriptionTagsFromApi, true);
        $summaries = [];
        $prio = 1;
        foreach (OutboundJsonTree::templateNodesFromRoot($root) as $node) {
            $tag = OutboundJsonTree::tag($node);
            if ($tag !== '' && isset($subs[$tag])) {
                continue;
            }
            $summaries[] = self::summarizeNode($node, $prio++, 'template');
        }

        return $summaries;
    }

    /**
     * @param list<object> $subscriptionOutboundsFromApi
     * @param list<string> $subscriptionTagsFromApi
     * @return array{template: list<array<string, mixed>>, subscription: list<array<string, mixed>>}
     */
    public static function buildUiOutboundLists(
        object $root,
        array $subscriptionOutboundsFromApi,
        array $subscriptionTagsFromApi,
    ): array {
        $template = self::summarizeTemplateRoot($root, $subscriptionTagsFromApi);
        $templateTags = [];
        foreach ($template as $row) {
            $templateTags[(string) ($row['tag'] ?? '')] = true;
        }
        $subscription = self::summarizeSubscriptionApiOutbounds(
            $subscriptionOutboundsFromApi,
            $subscriptionTagsFromApi,
            count($template) + 1,
        );
        $subscription = array_values(array_filter(
            $subscription,
            static fn (array $row): bool => !isset($templateTags[(string) ($row['tag'] ?? '')]),
        ));

        return ['template' => $template, 'subscription' => $subscription];
    }

    /**
     * @param list<object> $subscriptionOutboundsFromApi from obj.subscriptionOutbounds
     * @param list<string> $subscriptionTagsFromApi
     * @return list<array<string, mixed>>
     */
    public static function summarizeSubscriptionApiOutbounds(
        array $subscriptionOutboundsFromApi,
        array $subscriptionTagsFromApi,
        int $startPriority = 1,
    ): array {
        $summaries = [];
        $prio = $startPriority;
        $seenTags = [];
        foreach ($subscriptionOutboundsFromApi as $node) {
            if (!is_object($node)) {
                continue;
            }
            $tag = OutboundJsonTree::tag($node);
            if ($tag === '' || isset($seenTags[$tag])) {
                continue;
            }
            $seenTags[$tag] = true;
            $summaries[] = self::summarizeNode($node, $prio++, 'subscription');
        }
        foreach ($subscriptionTagsFromApi as $tag) {
            if ($tag === '' || isset($seenTags[$tag])) {
                continue;
            }
            $seenTags[$tag] = true;
            $placeholder = (object) ['tag' => $tag, 'protocol' => 'subscription'];
            $summaries[] = self::summarizeNode($placeholder, $prio++, 'subscription');
        }

        return $summaries;
    }

    /** @return array<string, mixed> */
    public static function summarizeNode(object $node, int $priority, string $kind): array
    {
        $tag = OutboundJsonTree::tag($node);
        $protocol = OutboundJsonTree::protocol($node) ?: '—';

        return [
            'priority' => $priority,
            'tag' => $tag,
            'protocol' => $protocol,
            'enabled' => true,
            'protected' => self::isProtectedTag($tag),
            'kind' => $kind,
            'fingerprint' => OutboundJsonTree::fingerprint($node),
            'raw' => OutboundRedactor::redactNode($node),
        ];
    }

    public static function isProtectedTag(string $tag): bool
    {
        $t = strtolower(trim($tag));

        return in_array($t, self::PROTECTED_TAGS, true);
    }

    /** @deprecated use OutboundJsonTree; kept for tests migrating off arrays */
    public static function tagOf(array $outbound): string
    {
        $tag = $outbound['tag'] ?? '';

        return is_string($tag) ? trim($tag) : '';
    }

    /** @deprecated */
    public static function fingerprint(array $outbound): string
    {
        $json = json_encode($outbound, JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json !== false ? $json : '');
    }
}
