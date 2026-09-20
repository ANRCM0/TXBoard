# Third-Party and Provenance Notes

## API

`api/` derives from Xboard and retains the MIT license supplied in `api/LICENSE`.

## Node runtime

`node/` derives from `cedar2025/Xboard-Node`. The historical repository described itself as MPL-2.0 but did not provide a top-level license file in the imported source snapshot. Keep this repository private and do not publish binaries or container images until the inherited licensing terms and notices have been verified.

## Embedded engines and dependencies

TX-Node links to the Go modules declared in `node/go.mod`. Their licenses remain governed by their respective upstream projects. Generated dependency inventories should be attached to public releases once redistribution is approved.
