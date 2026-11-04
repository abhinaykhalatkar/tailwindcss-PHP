<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Candidate\Value;

/**
 * A named value: the text after the root, as written (`4`, `red-500`, `1/2`).
 *
 * `fraction` is true when the name is `<digits>/<digits>` (design doc 07
 * step 8) — the reading a utility such as `w-` uses for `w-1/2`. Public
 * properties set once by the constructor (design doc 13 #22).
 */
final class NamedValue implements ValueInterface
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var bool
     */
    public $fraction;

    public function __construct(string $name, bool $fraction = false)
    {
        $this->name = $name;
        $this->fraction = $fraction;
    }

    /**
     * Whether $name is `<digits>/<digits>`, both sides non-empty.
     */
    public static function isFraction(string $name): bool
    {
        $slash = \strpos($name, '/');

        if ($slash === false || $slash === 0) {
            return false;
        }

        $length = \strlen($name);

        return $slash < $length - 1
            && \strspn($name, '0123456789') === $slash
            && \strspn($name, '0123456789', $slash + 1) === $length - $slash - 1;
    }
}
