<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Utility\Registry;

/**
 * `@utility` (design doc 07 §Pipeline, doc 08 §Directives).
 *
 * The static form `@utility name { … }` registers its body — parsed CSS
 * nodes, never raw text (T-15) — with {@see Registry::addCustom()}; nested
 * rules (`&::-webkit-scrollbar`) stay nested. An `@apply` inside the body is
 * deferred like any other: the body is then held by an `&` host rule that the
 * assembler fills (`& { … }` flattens to the utility's own selector).
 *
 * The functional form `@utility name-* { --value(…) }` is T2 (M3) and is
 * reported as unsupported.
 *
 * @phpstan-import-type Apply from ProcessedSheet
 */
final class UtilityHandler
{
    /**
     * @param list<Apply> $applies
     */
    public static function handle(AtRule $utility, Registry $registry, array &$applies, DiagnosticBag $bag): void
    {
        $name = \trim($utility->params);

        if ($utility->nodes === null) {
            $message = \sprintf('@utility %s has no body.', Processor::echo($name));
            self::report($utility, Diagnostic::E_CSS_SYNTAX, $message, $bag);

            return;
        }

        if (\substr($name, -2) === '-*') {
            self::report(
                $utility,
                Diagnostic::E_UNSUPPORTED_DIRECTIVE,
                \sprintf(
                    'The functional @utility %s (--value()) is not supported by the PHP engine yet (M3); '
                    . 'use the CLI driver.',
                    Processor::echo($name)
                ),
                $bag
            );

            return;
        }

        $before = \count($applies);
        $body = new Rule('&', $utility->nodes, $utility->line, $utility->col);
        $host = ApplyHandler::extract($body, $name, $applies, $bag);
        $direct = false;

        for ($i = $before, $count = \count($applies); $i < $count; ++$i) {
            $direct = $direct || $applies[$i]['host'] === $host;
        }

        try {
            $registry->addCustom($name, $direct ? [$host] : $host->nodes);
        } catch (\InvalidArgumentException $exception) {
            \array_splice($applies, $before);
            $message = \sprintf('@utility %s is not a valid utility name.', Processor::echo($name));
            self::report($utility, Diagnostic::E_CSS_SYNTAX, $message, $bag);
        }
    }

    private static function report(AtRule $utility, string $code, string $message, DiagnosticBag $bag): void
    {
        $bag->add(Processor::diagnostic($code, $message, $utility->line));
    }
}
