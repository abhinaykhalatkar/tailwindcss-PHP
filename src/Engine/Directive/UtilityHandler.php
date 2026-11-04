<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Utility\Compiler;
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
 * A declaration whose property is not a plain name (a reset stem such as
 * `--x-*: initial`, say) is reported with `E_CSS_SYNTAX` and left out of the
 * registered body: the compiler's emission guard would otherwise refuse the
 * whole utility without a word.
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
        $nodes = self::withoutBadDeclarations($utility->nodes, $utility, $bag);
        $body = new Rule('&', $nodes, $utility->line, $utility->col);
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

    /**
     * The nodes without any declaration whose property is not a plain name,
     * each one reported; nested rules and at-rules are cleaned in place.
     *
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private static function withoutBadDeclarations(array $nodes, AtRule $utility, DiagnosticBag $bag): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration && !Compiler::isPropertyName($node->property)) {
                $message = \sprintf(
                    '@utility %s has a declaration with the invalid property name %s; it is left out.',
                    Processor::echo(\trim($utility->params)),
                    Processor::echo($node->property)
                );
                // Declarations carry a line only in the parser's debug mode: the directive's line is the position.
                $bag->add(Processor::diagnostic(Diagnostic::E_CSS_SYNTAX, $message, $utility->line));

                continue;
            }

            if ($node instanceof Rule) {
                $node->nodes = self::withoutBadDeclarations($node->nodes, $utility, $bag);
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                $node->nodes = self::withoutBadDeclarations($node->nodes, $utility, $bag);
            }

            $kept[] = $node;
        }

        return $kept;
    }

    private static function report(AtRule $utility, string $code, string $message, DiagnosticBag $bag): void
    {
        $bag->add(Processor::diagnostic($code, $message, $utility->line));
    }
}
