# Alpha.7 AI Handoff

Status: design direction recorded on 2026-10-30, followed by local alpha.7 implementation.
Read [ALPHA7.md](ALPHA7.md) for the bounded implementation, API and remaining limits.
Runtime instructions are packaged as `wordpress/atshift-deft/AI-GUIDE.md` and
included in authenticated context exports. This design note is not an API schema.

## Instructions for the Article Assistant

Help the reader understand the intended message and take the intended action.
The goal is effective communication, not generating more components or choosing
an elaborate layout. Work through the user's existing AI conversation; do not
require the user to assemble blocks, name CSS classes or write component code.

### Reuse Before Creation

1. Understand the intended audience, communication goal, important information
   and explicit user preferences. Ask only when a material ambiguity prevents a
   responsible proposal; do not turn normal article creation into a questionnaire.
2. Inspect the available component catalog and relevant full definitions before
   proposing a new component. Match purpose, field meanings and structure, not
   merely the component name or visual resemblance.
3. Prefer an existing component, a supported named presentation, or a combination
   of existing components that genuinely meets the need.
4. Different copy, images, article titles or repeated instances do not justify a
   new definition. Do not create near-duplicates such as event_a/event_b/event_c
   for the same structure.
5. If existing definitions cannot meet the need, identify the candidates considered
   and the missing capability. Propose only the smallest necessary new structure.
   Do not distort meaning or hide essential information merely to avoid creation.
6. Follow the current API and the acting user's permissions. A rationale does not
   authorize new HTML/CSS, shared registration, permission changes or publication.
   If generation is unavailable, report the gap honestly instead of inventing a
   successful registration or asking the user to write HTML/CSS themselves.

### Choose Presentation for the Reader

Recommend a presentation with a short, concrete reason. Respect an explicit user
preference, while explaining relevant readability or accessibility trade-offs.
Do not treat one presentation as universally superior.

Q&A is the reference example:

- Short answers that everyone should read: consider an initially visible list.
- Many independent questions where readers need only some answers: consider an
  accessible accordion, if that behavior is actually supported.
- Essential warnings, deadlines or required actions: keep these initially visible
  rather than relying only on collapsed content.

These are contextual examples, not fixed question-count thresholds. Consider answer
length, scanning and comparison, mobile use, keyboard access, clear controls and
fallback behavior. Verify the rendered result; a component name is not proof that
its interactions or accessibility work.

Keep the semantic Q&A content separate from its presentation in the future model.
Switching list/accordion presentation must preserve questions, answers, order and
stable identities. Do not recreate content or multiply definitions just to change
the look. A CSS-class variant alone does not necessarily provide different markup
or accessible interaction. Alpha.7 implements only a bounded native Q&A adapter,
not a general switching model for arbitrary component trees.

Example rationale, translated into the user's language in actual use:
"These answers are short and useful to every reader, so I suggest a visible list.
It lets readers check the whole set without opening individual questions."

### Respond in the User's Language

Respond in the language appropriate to the user, including their country/region,
not automatically in the language of the WordPress admin screen or API.
Use this preference order:

1. The user's explicit language and regional-variant preference.
2. The language being used in the current conversation.
3. A known user language/locale preference; country/region is only a fallback clue.

Do not infer a single language from nationality, location or IP address. Countries
can be multilingual. If the choice is genuinely unclear and matters, ask a brief
question; otherwise continue in the conversation's language without adding friction.

Localize suggestions, selection reasons, progress summaries, questions, approval
requests and plain-language error/recovery explanations. Keep stable IDs, field
keys, API names, code and literal UI labels intact; explain English UI labels in
the user's language when useful. Do not translate machine keys in payloads.

The article's target language is separate from the conversation language. For
example, a Japanese-speaking user can request an English article and receive the
proposal explanation in Japanese. Honor explicitly requested language, terminology
and regional conventions for the content itself. No automatic article translation
or locale detection feature is implied by this instruction document.

## Alpha.7 Implementation Handoff

- Make these rules available to the article-writing AI in the actual handoff or
  context/discovery response, not only as an internal development note. Choose the
  delivery format when implementing alpha.7; do not silently change the v1 kit or
  alpha.6 strict schemas.
- Design machine-readable component guidance for purpose, suitable/unsuitable uses,
  semantic content family, presentation choices and whether information is initially
  visible or requires interaction. These concepts are not new API property names yet.
- Support reuse with candidate lookup, duplicate checks and bounded creation. Exact
  duplicates and semantic similarity are different problems; similar-looking parts
  are not automatically interchangeable. Do not promise perfect semantic deduplication.
- Separate personal creation/use from shared registration and shared update/disable
  authority. Avoid a WordPress-administrator bottleneck in ordinary work without
  granting unrestricted HTML/CSS import to every author. Dedicated capabilities,
  validation boundaries and personal-component storage still need implementation.
- New personal components must not automatically enter the shared catalog. Define
  creation quotas and a deliberate sharing path; never silently delete old parts
  or alter saved articles when a limit is reached.
- Keep first-creator ownership, pinned component revisions, compare-and-swap saves,
  private media and exact-version review intact. Grant no capabilities to existing
  accounts automatically. Existing atshift Fields remains untouched.
- Routine supported choices should proceed to a finished proposal/preview without
  component-by-component approval. Ask for human intervention only for material
  uncertainty, an actual authority change or an irreversible action. Publication
  still requires its separately defined authorization; this note grants none.

## Acceptance Examples for Alpha.7

- Two announcements with different text/images reuse one suitable definition.
- A suitable existing presentation or combination is chosen without new registration.
- An unsupported structure produces a recorded gap and a minimal proposal, not a
  silently substituted or falsely functional component.
- Short Q&A and selective long Q&A receive reasoned, context-sensitive presentation
  suggestions; critical information remains visible and user preferences are respected.
- Presentation changes preserve semantic content and stable identities.
- Duplicate/racing creation and quota limits cannot cause uncontrolled growth or
  overwrite saved definitions; personal/shared ownership boundaries remain enforced.
- English admin/API surfaces do not force English replies to a Japanese-speaking
  user. An explicit different reply language overrides the conversation default.
- A requested foreign-language article does not silently change the language of
  explanations to the user; payload identifiers stay unchanged in all locales.

The implemented subset is exercised by `tests/wordpress-personal.php` and browser
interaction checks; general semantic selection and actual external-AI compliance
remain outside those tests. No commit, push, Release or deployment is implied.
