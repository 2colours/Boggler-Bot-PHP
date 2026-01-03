# PHP style guide in the project
**NOTE: instructions of the style guide can and should be applied retrospectively as well.**
- PSR-12 is applied
_Rationale_: consistency in PHP-verse plus whatever rationale the creators of PSR-12 had.
_Addendum_: the project has a dev dependency on phpcs/phpcbf, use that for formatting and notifying about problems (`composer format-check`, `composer format-autofix` shorthands available).
- `empty` should never be used
_Rationale_: the `empty` function performs a bool check against `false` (while guarding against errors from missing values) which is verbose and obfuscated on one hand, and misleading on the other hand (`empty('0')` equals `true` because `'0'` is understood as zero which is as `false` as it gets).
_Replacement_: appropriate comparison against literal; comparing the size of the content against 0.
- `==` should never be used on scalars
_Rationale_: this language has much more convoluted (bizarre, even) coercion rules than Javascript; most notoriously, strings that happen to contain numbers are always subject to numeric comparisons, even against other strings. We are not here to learn the gotchas of the language but to get things done. (In theory, comparison against well-known constants or literals could be safe enough but dumb consistency beats clever decisions when the rewards are this low.)
_Replacement_: `===`.
_Addendum_: sometimes it might be necessary to do structural equal check of arrays. In that case, `===` won't cut it; specify the types and use `==` with caution.
- blocks are always used with control structures such as `if`, `while`, `for`, `foreach`
_Rationale_: mainly consistency across the project; slightly also ease of refactoring.
- optional arguments are denoted with `?TypeName`
_Rationale_: it communicates the intent clearer than `T|null` and it's good to codify for consistency.
- single-line string literals without interpolation should always use single quoting (apostrophes)
_Rationale_: it clearly communicates lack of interpolation - and following this principle, double quoting can clearly communicate presence of interpolation. It is also somewhat a safety measure against accidental interpolation.
- message-based command handlers shouldn't return anything
_Rationale_: the return value of command handlers _could_ be used the content of the reply to the message; however, this is not flexible enough and it's by no means obvious to somebody unfamiliar. It's a bad API not to be relied on. It would probably be harder to migrate from it as well.
_Replacement_: `$ctx->reply($value)` (assuming `$ctx` as the message instance) instead of `return $value`.
- `is_null` is the preferred way to do a `null`-check
_Rationale_: in a well-designed codebase, `null`-checks are seldom used - `null` values shouldn't be propagated too much and in general missing values are a special enough case that might demand a design change overall. Since `is_null` is safe to use and stands out more than an `=== null` check, it's a good choice for presenting a special circumstance as special.
- `implode` is preferred over its alias `join`
_Rationale_: mainly consistency, and the fact that there is no plain `split` either way - the direct counterpart of this function is `explode`