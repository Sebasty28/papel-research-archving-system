# The PAPEL diagrams

Four diagrams, each as a **draw.io-editable SVG** and a **markdown
specification**. The SVG is the drawing; the markdown is what it is a drawing
of, and is what you hand to an agent asked to redraw or check it.

Generated 15 November 2026 from `database/schema.sql`, `app/models/`,
`config/core.php` and the page handlers.

| Diagram | Specification | Drawing |
|---|---|---|
| Entity relationship | [ERD.md](ERD.md) | [papel_erd.drawio.svg](papel_erd.drawio.svg) |
| Use case | [USE_CASE.md](USE_CASE.md) | [papel_use_case.drawio.svg](papel_use_case.drawio.svg) |
| Class | [CLASS_DIAGRAM.md](CLASS_DIAGRAM.md) | [papel_class.drawio.svg](papel_class.drawio.svg) |
| Sequence | [SEQUENCE_DIAGRAM.md](SEQUENCE_DIAGRAM.md) | [papel_sequence_submission.drawio.svg](papel_sequence_submission.drawio.svg) |

`SEQUENCE_DIAGRAM.md` specifies four sequences. Only the first, a paper from
Submit to published, is drawn; the other three (signing in and the password
gate, a manuscript request, Extract with AI) are specified with Mermaid source
and have no SVG yet.

---

## Opening one in draw.io

A `.drawio.svg` is an ordinary SVG that also carries the diagram's model in its
root element's `content` attribute. So one file is both things:

- **To look at it**, open it in a browser, drop it in a document, or put it in
  a slide. It is a plain SVG and scales to any size with no loss.
- **To edit it**, open <https://app.diagrams.net> (or the draw.io desktop app)
  and choose **File > Open From > Device**, then pick the `.drawio.svg`.
  draw.io reads the `content` attribute and gives you the real shapes: tables
  whose rows you can type into, ovals you can rename, connectors that stay
  attached when you drag a box.

Save with **File > Save** to keep it editable. If you export instead, choose
**File > Export as > SVG** and tick **Include a copy of my diagram**, or the
next person gets a picture they cannot edit.

GitHub renders these as pictures in the browser, which is the point: the same
file serves a reader and an editor.

### If you would rather have a plain `.drawio` file

In draw.io, open the SVG as above and then **File > Save As** with the
`.drawio` extension. Nothing is lost: the XML inside the SVG is exactly what a
`.drawio` file holds.

## Editing: by hand or by regenerating

Both diagrams and specifications were produced by a generator that emits the
picture and the model from one description, so they cannot drift from each
other. That generator is a scratch script and is **not** in the repository,
which means:

- **Small corrections**: edit the `.drawio.svg` in draw.io and save. This is
  the expected path.
- **A change that affects many boxes** (a migration adding tables, a role
  gaining pages): update the markdown specification first, then redraw. The
  specification is written so that an agent can rebuild the drawing from it.

Either way, **change the specification too**. A drawing that disagrees with its
specification is worse than having neither, because the next reader cannot tell
which one is lying.

## Checking a diagram is still true

Each specification ends with a section on how to check it against the running
system rather than by reading it: SQL against `information_schema` for the ERD,
`grep` for `require_role()` for the use cases, `grep` for the method names for
the class diagram, and reading the handler top to bottom for the sequence.

Do that before handing any of these to a reader. Two of the files in this
folder went stale without anybody noticing, which is the next section.

---

## Superseded

| File | Why it is still here |
|---|---|
| `PAPEL_erd.svg`, `gen_erd.php` | The previous ERD, drawn for **Figma import** rather than draw.io, and stale: its subtitle says "16 of 20 tables" against today's 22, so it predates `manuscript_requests` and `paper_manuscripts` |
| `PAPEL_use_case_diagram.svg`, `gen_usecase.php` | The previous use case diagram, also Figma-shaped, and stale in the way `CLAUDE.md` warns about: it still calls the Subject Adviser the "Research Adviser", a name retired on 12 November 2026 |

They are kept rather than deleted because they are referenced from elsewhere
and because `gen_erd.php` and `gen_usecase.php` are the only PHP diagram
generators in the project. **Do not hand either SVG to a reader.** If the Figma
route is still wanted, regenerate from the PHP first, and bring the renamed
role and the two missing tables with you.
