# PAPEL development iterations

These nine descriptions use the version labels chosen for the research paper.
They describe the development stages and the current capabilities of the same
system. Work completed after the original ninth-iteration write-up is included
in the final iteration; the features and stored data are retained. The current
version is **v0.12.0** until the project owner explicitly changes it.

## Iteration 1 — v0.1.0 Prototype

The first iteration established the basic submission, review, approval, and
archiving workflow. Students could submit research papers, while staff used
role-specific dashboards to process them. The prototype integrated Google
Drive for manuscript storage, Groq-powered document analysis and content
extraction, basic protection against SQL injection, and an analytics dashboard
for submission statistics. Initial testing identified the need for a clearer
module structure and stronger protection around each application file.

## Iteration 2 — v0.5.0 Alpha

The second iteration reorganized the code into application, configuration,
database, documentation, and notification modules. Database migration scripts,
background jobs, error handling, and session controls strengthened the technical
foundation. The role model expanded to include the Librarian and Head of
Academic Programs. During this stage the team also used one-time-password
verification and the `openai/gpt-oss-120b` model through Groq. OTP was part of
the development history; it is no longer in the current sign-in flow.

## Iteration 3 — v0.6.0 Alpha-to-Beta transition

This iteration put the established Alpha baseline under version control and
prepared it for Beta development. The team could track changes, test fixes
against a known baseline, and continue the functional work without treating
each experiment as a separate prototype.

## Iteration 4 — v0.7.0 Beta

The role hierarchy was aligned with the institution. Guest, Student,
Librarian, Subject Adviser, Head of Academic Programs, Research Coordinator,
and Director accounts received appropriate access and work areas. The review
workflow developed into the current two approval steps: Subject Adviser review,
then Research Coordinator approval and publication. The Head and Director read
the results rather than approving papers. A bot check was added to sign-in.
The v0.7.1 patch refined the interface and chatbot reply formatting without
introducing a new capability.

## Iteration 5 — v0.8.0 Beta

This iteration prepared shared components for wider use. The team consolidated
manuscript reading around a common PDF viewer, improved the upload and archive
interfaces, and refined how panels, navigation, and page layouts work across
desktop and mobile screens. These changes reduced duplicated page behavior and
provided a base for later editing and preview features.

## Iteration 6 — v0.9.0 Release Candidate

The project gained separate staging and production deployment workflows and an
automated test suite. Staging provided a place to check the full submission and
review flow before production. This was the final iteration in the original
development plan; the later iterations record the additional work that followed
testing and continued use.

## Iteration 7 — v0.10.0 Release Candidate (AI layer)

The chatbot was developed into Corpus. A Cloudflare Worker proxy allowed the
hosted system to reach the Groq API despite the hosting provider's outbound
request limits. The AI layer supported document analysis during upload and
contextual help, with clearer replies and safeguards for failed or excessive
requests.

## Iteration 8 — v0.11.0 Release Candidate (workflow)

An editable Review Checklist gave Subject Advisers a structured way to assess
submissions. The faculty review desk and Google Drive upload handling were
revised. Returning a paper sent it back to draft with feedback; approval still
ended with the Research Coordinator.

## Iteration 9 — v0.12.0 Release Candidate (current version)

The final iteration brought the paper upload, review, repository, and
administrative features to their current state. Students can submit both IMRAD
and full manuscripts, choose or define written sections, work in the two-pane
upload editor, and view a live preview rendered from the public paper page.
They can request time-limited access to full manuscripts through the Librarian.
The Public Repository gained improved searching, browsing, reading, and PDF
viewing on desktop and mobile screens.

Staff management now supports spreadsheet import and one person holding two
posts; the same account can open either authorized desk. The Research
Coordinator can manage research operations, while the
Director retains control of administrator accounts and system-wide records.
Support requests, notifications, accessibility, dark palettes, loading
behavior, and page performance received further fixes. These are refinements
of the current system within v0.12.0, not additional numbered iterations. The
next version requires an explicit decision by the project owner.
