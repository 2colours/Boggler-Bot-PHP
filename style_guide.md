# PHP style guide in the project
**NOTE: instructions of the style guide can and should be applied retrospectively as well.**
- PSR-12 is applied
_Rationale_: consistency in PHP-verse plus whatever rationale the creators of PSR-12 had.
- `empty` should never be used
_Rationale_: the `empty` function performs a bool check against `false` (while guarding against errors from missing values) which is verbose and obfuscated on one hand, and misleading on the other hand (`empty('0')` equals `true` because `'0'` is understood as zero which is as `false` as it gets).
_Replacement_: appropriate comparison against literal; comparing the size of the content against 0.
- `==` should never be used
_Rationale_: this language has much more convoluted (bizarre, even) coercion rules than Javascript; most notoriously, strings that happen to contain numbers are always subject to numeric comparisons, even against other strings. We are not here to learn the gotchas of the language but to get things done. (In theory, comparison against well-known constants or literals could be safe enough but dumb consistency beats clever decisions when the rewards are this low.)
_Replacement_: `===`.
- blocks are always used with control structures such as `if`, `while`, `for`, `foreach`
_Rationale_: mainly consistency across the project; slightly also ease of refactoring.
- single-line string literals without interpolation should always use single quoting (apostrophes)
_Rationale_: it clearly communicates lack of interpolation - and following this principle, double quoting can clearly communicate presence of interpolation. It is also somewhat a safety measure against accidental interpolation.