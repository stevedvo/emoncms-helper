# Repository formatting preferences

These are the owner's deliberate formatting preferences. Follow them when writing
or editing this app, even where they differ from framework or formatter defaults.
Use `public/css/control-panel.css`, `public/js/control-panel.js`, and
`app/Services/ControlSettings.php` as examples, together with the surrounding code.

## General

- Use UTF-8 without a BOM, CRLF line endings, and a final newline, as specified in
  `.editorconfig`. Indent with tabs, displayed at four columns; use spaces for
  alignment within a line.
- Put opening braces on the next line for CSS rules and multiline PHP/JavaScript
  blocks (Allman style). Align closing braces with the statement or selector.
- Separate logical groups with blank lines. Preserve the breathing room around
  control-flow blocks and before returns where the surrounding code uses it.
- Preserve intentional horizontal alignment and compact expressions. Do not force
  long calls, conditions, array entries, or HTML attribute lists onto multiple
  lines to satisfy an arbitrary line-length limit. Expand nested Blade markup
  according to the Blade rules below, regardless of line length.
- Match the file being edited where existing conventions vary. Older code,
  framework scaffolding, and tests are not all formatted identically. Do not
  reformat unrelated code or run a formatter across the repository. In particular,
  do not apply Pint, PHP-CS-Fixer, or Prettier defaults over these preferences.
- Keep formatting edits separate from behavioural changes where practical. Check
  syntax-sensitive whitespace instead of using indiscriminate find/replace.

## CSS

- Put the selector on its own line, then `{` on the next line. Indent declarations
  one tab and put each declaration on a separate line.
- Use one space after a declaration colon and end every declaration with `;`.
  A CSS rule's closing `}` does not need a semicolon.
- Keep a blank line between rule blocks and indent nested rules inside media queries.
- Use one space after each comma in selector lists, font lists, and function
  arguments, such as `button, input` and `repeat(4, minmax(0, 1fr))`. When a list
  spans multiple lines, keep the newline and indentation after the comma instead.
- Follow the existing media-query spelling, such as `@media(max-width: 620px)`.
- Declaration-colon spacing does **not** apply to selectors: `:root`, `:hover`,
  `:focus-visible`, and `::before` must keep their colons attached to their names.
  In particular, `: root` is invalid and prevents the shared colour variables loading.

```css
:root
{
	--amber-bg: #fbf1dc;
}

.amber
{
	background: var(--amber-bg);
	color: var(--amber);
}
```

## JavaScript

- Use tabs and Allman braces for functions, control flow, and multiline callbacks
  or arrow-function bodies. Put `else`, `catch`, and `finally` on their own lines.
- Use braces around control-flow bodies, including short guard clauses.
- Use semicolons for statements and the appropriate `};` or `});` when closing
  function expressions or callbacks. Do not apply that suffix to CSS rule blocks.
- Use spaces around operators and `=>`, after argument commas, and between control
  keywords and `(`. Keep function calls tight, such as `refresh()`.
- Prefer single-quoted strings and template literals for interpolation, following
  nearby code. Compact object literals such as `{ key: value, other: value }` are fine.
- Preserve existing short inline callbacks, such as `() => { dirty = false; }`;
  do not expand them or reflow existing compact snippets merely for consistency.

```js
const refresh = () =>
{
	if (busy)
	{
		return;
	}

	status.textContent = 'Refreshing…';
};
```

## PHP

- In the app's indented PHP style, keep `<?php` at column one and use one base tab
  for the namespace, imports, class declaration, or top-level config code. Add a
  tab for each nested level. Preserve files such as tests that use a different
  top-level indentation rather than changing them wholesale.
- Use Allman braces for classes, methods, control flow, and multiline closures.
  Put `else`, `catch`, and `finally` on their own lines.
- Put a space before and after the return-type colon in function and method
  signatures, such as `method() : array`. The control-panel service uses
  `function()` and `function() use (...)` for empty-argument closures.
- Keep PHP concatenation tight, for example `'prefix'.$value.'suffix'`. Match
  nearby cast spacing; the indented app code commonly uses `(float)$value`.
- Use short arrays `[]`. In multiline associative arrays, put each key-value pair
  on its own line and use spaces to align every `=>` in the array vertically.
  Do not group multiple pairs on one line, even where nearby code does so.
  For multiline array arguments, use `[` on the next line, aligned with the call:

```php
<?php
	ActivityLog::create(
	[
		'controller' => self::class,
		'method'     => 'save',
		'level'      => 'info',
		'message'    => 'Changed setting '.$key,
	]);
```

- Indent fluent method continuations one further tab and preserve local blank lines.
- Follow nearby string quoting rather than changing existing quotes gratuitously.

## Blade views

- Use tabs for indentation and make the nesting visible. Put each structural HTML
  element on its own line; never pack multiple nested or sibling `<div>` elements
  into one line.
- For containers with child elements, put the opening tag, indented children, and
  closing tag on separate lines. Apply this to sections, forms, navigation, lists,
  tables and other structural markup as well as divs.
- A simple text-only element can stay on one line, such as
  `<div class="eyebrow">KEEP THE USEFUL ONES</div>`. Keep inline text, units and
  punctuation together where introducing whitespace would change their display.
- Put block Blade directives such as `@if`, `@else`, `@endif`, `@foreach`,
  `@endforeach`, `@forelse`, `@empty`, and `@endforelse` on separate lines. Indent
  their contents one further tab and align branches and closing directives with
  their opening directive. Expand `@section` and `@push` blocks the same way.
- Put standalone directives such as `@csrf` and `@method` on their own lines.
  Attribute directives such as `@checked`, `@selected`, and conditional attributes
  remain inside their HTML opening tag.
- Do not wrap long attribute lists merely to satisfy a line-length limit. Preserve
  expressions and attributes while expanding the surrounding markup.
- Preserve whitespace-sensitive content, especially `<pre>` and `<textarea>`
  values; do not add indentation or newlines inside those values.

```blade
<div class="section-heading">
	<div>
		<div class="eyebrow">KEEP THE USEFUL ONES</div>
		<h2 id="saved-heading">Saved searches</h2>
	</div>
	@if($selected)
		<span class="pill neutral">Loaded: {{ $selected->name }}</span>
	@endif
</div>
```
