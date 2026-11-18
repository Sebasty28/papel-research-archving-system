# Releasing PAPEL

The current version is **0.12.0**. The project owner chose this number for the
nine development iterations in the research paper. Keep it until the owner
explicitly requests a different version. Routine fixes and features do not
change `VERSION` or create a new release tag during this freeze. The current
iteration descriptions are in [PAPEL_Development_Iterations.md](PAPEL_Development_Iterations.md).

## Where the displayed version comes from

The repository's root `VERSION` file holds one line, without a `v` prefix:

```text
0.12.0
```

The footer reads that file. Staging adds the deployed commit after a `+`, so
its label can identify a particular push while the version number stays at
0.12.0. Production uses the number in the committed file. Neither deployment
workflow changes the repository's `VERSION` file.

## Deployment

Pushing `main` starts the staging deployment. Pushing a tag beginning with
`v` starts the production deployment. The current production tag is
`v0.12.0`, pointing to the commit whose `VERSION` file says `0.12.0`.
The production workflow also permits a manual run from an existing tag.

Do not create another `v*` tag for a past commit: pushing it would deploy
that old code to production. The earlier 1.x tags and GitHub Release were
retired on 18 November 2026 when the owner corrected the version scheme.
Their underlying commits and original commit messages remain in Git history.
The published GitHub Release is now `v0.12.0`.

## When the owner authorizes a new version

1. Decide the next number and update the one line in `VERSION`.
2. Commit and push the change to `main`; verify it on staging.
3. Create a tag with the `v` prefix on that exact commit and push it only
   when that build should go to production.
4. Check the production Actions run and the published site's footer.

Use Semantic Versioning for that later decision: MAJOR for an incompatible
change, MINOR for a compatible feature, and PATCH for a fix without a feature.
Do not infer permission to release from an ordinary commit or from these
instructions.
