<?php

declare(strict_types=1);

use App\Xui\XrayRoutingRules;
use PHPUnit\Framework\TestCase;

final class XrayRoutingRulesTest extends TestCase
{
    public function testDetectsProtectedApiRule(): void
    {
        $rule = ['type' => 'field', 'inboundTag' => ['api'], 'outboundTag' => 'api'];
        self::assertTrue(XrayRoutingRules::isProtectedRoutingRule($rule));
    }

    public function testDedupeRemovesIdenticalRules(): void
    {
        $a = ['type' => 'field', 'domain' => 'example.com', 'outboundTag' => 'direct'];
        $out = XrayRoutingRules::dedupeRules([$a, $a]);
        self::assertCount(1, $out);
    }

    public function testMergeKeepsProtectedFirst(): void
    {
        $protected = [['inboundTag' => ['api'], 'outboundTag' => 'api']];
        $editable = [['domain' => 'test.com', 'outboundTag' => 'direct']];
        $merged = XrayRoutingRules::mergeRulesForSave($protected, $editable);
        self::assertSame('api', $merged[0]['outboundTag']);
        self::assertSame('test.com', $merged[1]['domain']);
    }

    public function testValidateUnknownOutboundFails(): void
    {
        $config = [
            'outbounds' => [['tag' => 'direct']],
            'routing' => ['balancers' => []],
        ];
        $rules = [['type' => 'field', 'domain' => 'x.com', 'outboundTag' => 'missing']];
        $errors = XrayRoutingRules::validateRulesForConfig($config, $rules);
        self::assertNotEmpty($errors);
    }

    public function testRuleMatchesSelectorByOutbound(): void
    {
        $rule = ['outboundTag' => 'warp', 'domain' => 'google.com'];
        self::assertTrue(XrayRoutingRules::ruleMatchesSelector(['outboundTag' => 'warp'], $rule));
    }

    public function testInboundValidationUsesApiInboundTags(): void
    {
        $config = ['outbounds' => [['tag' => 'direct']], 'routing' => ['balancers' => []], 'inbounds' => []];
        $rules = [
            ['type' => 'field', 'inboundTag' => ['in-443-tcp'], 'outboundTag' => 'direct'],
        ];
        $errors = XrayRoutingRules::validateRulesForConfig($config, $rules, ['in-443-tcp']);
        self::assertSame([], $errors);
    }

    public function testUnchangedPriorRuleSkipsInboundRevalidation(): void
    {
        $prior = ['type' => 'field', 'inboundTag' => ['legacy-inbound'], 'outboundTag' => 'direct'];
        $config = ['outbounds' => [['tag' => 'direct']], 'routing' => ['balancers' => []], 'inbounds' => []];
        $merged = [['inboundTag' => ['api'], 'outboundTag' => 'api'], $prior];
        $errors = XrayRoutingRules::validateRulesForConfig($config, $merged, [], [$prior]);
        self::assertSame([], $errors);
    }

    public function testNewRuleWithUnknownInboundFails(): void
    {
        $config = ['outbounds' => [['tag' => 'direct']], 'routing' => ['balancers' => []], 'inbounds' => []];
        $rules = [['type' => 'field', 'inboundTag' => ['does-not-exist'], 'outboundTag' => 'direct']];
        $errors = XrayRoutingRules::validateRulesForConfig($config, $rules, ['in-443-tcp']);
        self::assertNotEmpty($errors);
    }
}
