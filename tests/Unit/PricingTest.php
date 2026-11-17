<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Pricing\GenerationPricer;
use App\Pricing\Nano;
use App\Pricing\PricingSchedule;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;

function standardRates(): Rates
{
    return new Rates('5.00', '10.00', '40.00', '12.50');
}

describe('Nano', function () {
    it('round-trips Pixelforge cost strings exactly', function (string $usd, int $nano) {
        expect(Nano::fromUsdString($usd))->toBe($nano)
            ->and(Nano::toUsdString($nano))->toBe($usd);
    })->with([
        ['0.000000000', 0],
        ['0.046125000', 46_125_000],
        ['0.373875000', 373_875_000],
        ['12.000000001', 12_000_000_001],
    ]);

    it('formats readable amounts without losing a digit', function () {
        expect(Nano::format(46_125_000))->toBe('$0.046125')
            ->and(Nano::format(0))->toBe('$0.0000')
            ->and(Nano::format(381_887_500))->toBe('$0.3818875')
            ->and(Nano::format(1))->toBe('$0.000000001');
    });

    it('rejects anything that is not a plain USD amount', function (string $value) {
        Nano::fromUsdString($value);
    })->with(['-1.00', '1.0000000001', 'abc', '1e3'])->throws(InvalidArgumentException::class);
});

describe('Rates', function () {
    it('prices the docs example exactly', function () {
        $usage = new TokenUsage(12, 765, 6450, 3184);

        expect(standardRates()->costNano($usage))->toBe(305_510_000);
    });

    it('rounds half up only for rates with more than three decimals', function () {
        // 0.0005 dollars per 1M tokens is half a nanodollar per token.
        $rates = new Rates('0.0005', '0', '0', '0');

        expect($rates->costNano(new TokenUsage(1, 0, 0, 0)))->toBe(1)
            ->and($rates->costNano(new TokenUsage(3, 0, 0, 0)))->toBe(2);
    });

    it('rejects a rate that is not a decimal', function () {
        new Rates('five', '10.00', '40.00', '12.50');
    })->throws(InvalidArgumentException::class);
});

describe('GenerationPricer', function () {
    $pricer = new GenerationPricer(25);

    it('adds the markup exactly', function () use ($pricer) {
        expect($pricer->userCharge(305_510_000))->toBe(381_887_500)
            ->and($pricer->userCharge(0))->toBe(0);
    });

    it('rounds the markup half up to a whole nanodollar', function () use ($pricer) {
        // 10 * 1.25 = 12.5 nanodollars
        expect($pricer->userCharge(10))->toBe(13)
            ->and($pricer->userCharge(2))->toBe(3);
    });

    it('matches the docs worked example for the most a generation can cost', function () use ($pricer) {
        // high, 1536x1024, a 400-character prompt, 2 reference images: $0.373875
        $estimate = $pricer->estimate(
            PricingSchedule::fromArray(pixelforgePricing()),
            str_repeat('a', 400),
            ImageSize::Landscape,
            ImageQuality::High,
            2,
        );

        expect($estimate->providerMaxNano)->toBe(373_875_000)
            ->and($estimate->userMaxNano)->toBe(467_343_750)
            ->and($estimate->textTokensMax)->toBe(115)
            ->and($estimate->imageTokens)->toBe(1530);
    });

    it('counts characters, not bytes, in the prompt', function () use ($pricer) {
        $schedule = PricingSchedule::fromArray(pixelforgePricing());

        $ascii = $pricer->estimate($schedule, str_repeat('a', 40), ImageSize::Square, ImageQuality::Low, 0);
        $emoji = $pricer->estimate($schedule, str_repeat('🎨', 40), ImageSize::Square, ImageQuality::Low, 0);

        expect($emoji->textTokensMax)->toBe($ascii->textTokensMax);
    });

    it('always brackets the real price, whatever Pixelforge reports', function () use ($pricer) {
        $schedule = PricingSchedule::fromArray(pixelforgePricing());
        mt_srand(42);

        foreach (ImageQuality::cases() as $quality) {
            foreach (ImageSize::cases() as $size) {
                foreach ([0, 1, 4] as $images) {
                    $prompt = str_repeat('x', mt_rand(0, 4000));
                    $estimate = $pricer->estimate($schedule, $prompt, $size, $quality, $images);
                    $estimated = intdiv(mb_strlen($prompt) + 3, 4);
                    $reasoning = $schedule->reasoningTokens[$quality->value];

                    // Pixelforge's real text count is within 15% of the estimate; reasoning is anywhere in range.
                    foreach ([floor($estimated * 0.85), ceil($estimated * 1.15)] as $realText) {
                        foreach ([$reasoning['min'], $reasoning['max']] as $realReasoning) {
                            $actual = standardRates()->costNano(new TokenUsage(
                                (int) $realText,
                                $images * 765,
                                $schedule->outputTokens[$quality->value][$size->value],
                                $realReasoning,
                            ));

                            expect($actual)->toBeGreaterThanOrEqual($estimate->providerMinNano)
                                ->and($actual)->toBeLessThanOrEqual($estimate->providerMaxNano);
                        }
                    }
                }
            }
        }
    });
});

describe('PricingSchedule', function () {
    it('refuses a pricing table with a missing size', function () {
        $pricing = pixelforgePricing();
        unset($pricing['tokens']['output']['high']['1536x1024']);

        PricingSchedule::fromArray($pricing);
    })->throws(InvalidArgumentException::class);
});
