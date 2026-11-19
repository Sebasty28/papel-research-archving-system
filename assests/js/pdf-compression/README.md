# Local PDF compressor dependencies

- pdf-lib 1.17.1: https://github.com/Hopding/pdf-lib (MIT, pdf-lib.LICENSE).
- pako 2.1.0: https://github.com/nodeca/pako (MIT/Zlib, pako.LICENSE).

The minified distributions are vendored from their pinned npm releases via
jsDelivr. Runtime requests stay on this site; PDFs never go to a third-party
compression service. `worker.js` owns the conservative rewrite and verification
policy. See the PDF compression section in AGENTS.md for its limits.
