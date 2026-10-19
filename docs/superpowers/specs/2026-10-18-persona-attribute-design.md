# Attributes (persona attributes): visible, managed in Settings, shown per project

UI name since 18.10.2026: "Attributes". Code keeps `PersonaAttribute` (an `Attribute` model collides with Eloquent's cast class).

**Status:** decided 18.10.2026 (Jan-Niclas). Sibling of `2026-10-08-tags-und-statistik-design.md`.

## Why

The gender series varies one thing, the first name, and the model reads the demographic from it. For other
dimensions (origin, age) a name is a weak, implicit signal, and any stronger variant needed expert copies. We want
the fact itself to be a field of the persona that a project can reveal in three steps, without per-seat or
per-project persona overrides (rejected in the plan).

## Decisions

- **Per persona, not per project.** An expert carries its attribute values. The project only chooses whether they
  are shown. Name, role and description stay plain expert fields and never become project-specific.
- **A managed register.** Attributes are defined in the database and administered in Settings (admins only, like
  Settings → Tags). One definition = a name, a *self* sentence template, a *roster* text template, a position. Both
  templates must contain `:value`. A value is free text, one value per attribute and expert.
- **Visibility is a project setting**, `projects.attribute_visibility`, enum `AttributeVisibility`:
  - `none` (default): nothing shown. Prompts are **byte-identical** to the prompts before this feature.
  - `own`: the persona block shows the expert's own *self* lines, the others see nothing.
  - `all`: additionally the participants list shows the *roster* text behind each expert's role.
  The value stored is `none` | `own` | `all`.
- **Tags stay invisible to the models** (`TagsInvisibleToModelsTest` stays green). Attributes are the visible
  counterpart; tags remain the analysis label (`gender`, `origin`).
- **The blind judge never sees attributes** (it has no participants list and anonymous memory).
- **Frozen with the run.** At the first turn `run_config` gets `attribute_visibility` and, per `cast` entry, the
  rendered `attributes` (name, value, self line, roster text). Once a project has a `run_config`, prompts read the
  frozen lines for the experts in it, never the live templates, so editing a template later changes no past or
  running run. An expert that is not in the snapshot (added later) falls back to the live values.
  `attribute_visibility` can only be changed before the first turn.

## Rendering

- Persona partial: after `Your role is {role}.` one line per self sentence, in attribute order, only for `own`/`all`
  and only for attributes the expert has a value for. Example: `You are a woman.` / `You grew up in Owerri, Nigeria.`
- Participants partial: `- Name [E7] (Student)` becomes `- Name [E7] (Student, woman, from Owerri, Nigeria)` for
  `all`: role first, then the roster texts joined with `, `.
- `:value` is replaced with the stored value. Blade escaping and `PromptRenderer`'s entity decoding apply as for
  every other prompt text.

## Data

- `persona_attributes`: `id`, `name` (unique ignoring case and surrounding space), `self_template`,
  `roster_template`, `position`, timestamps.
- `expert_persona_attribute`: `expert_id`, `persona_attribute_id`, `value`, unique pair, cascade on both sides.
- `projects.attribute_visibility`: string, default `none`.

## Surfaces

Settings → Attributes (admin, `wire:sort` for position, rename/templates/delete with confirmation, "used
by N experts"), expert editor (one text field per definition, read only for non-admins), project form
(`x-projects.run-settings`) and `project:create --attributes=none|own|all`, bundles (definitions and values by
name, `attribute_visibility` on the project file; old bundles import unchanged), `init:experts` (optional
`attributes` object), study export (one column per attribute on `participants.csv`, `attribute_visibility` on
`runs.csv`).

## Guarantees (tests)

1. Same prompts with `none` as without the feature, for every pipeline's prompts (golden comparison).
2. An attribute text reaches a prompt only when the project reveals it (`own`: self line only; `all`: roster too).
3. The blind judge's prompt never contains an attribute text.
4. The frozen lines win over an edited template after the first turn.
5. Round trips: bundle export/import, `init:experts`, study export columns.
