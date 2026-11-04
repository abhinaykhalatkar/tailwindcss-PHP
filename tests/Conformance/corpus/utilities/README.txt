The utilities corpus is generated, not hand-written.

A later task adds tools/generate-corpus.php, which writes one <family>.txt here for every
row of the utility table (base usage, a theme value, a bare value, a fraction, an arbitrary
value, a (--var) value, a negative, an important, and combinations with md:, hover:, md:hover:,
dark:, group-hover: and [&>*]:). tools/generate-goldens.php then turns each file into a golden
under tests/Conformance/golden/utilities/.

Hand-written edge cases for variants and directives live in ../variants and ../directives.
