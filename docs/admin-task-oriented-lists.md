# Task-oriented administrator lists

This iteration starts with four frequent tasks: locating an account, finding an
order, managing a node, and checking a server. It keeps the approved monochrome
layout and the existing Core query and command contracts.

| Administrator question | Previous behavior | Current behavior |
| --- | --- | --- |
| Is this list empty, or did my search miss? | One generic no-data message. | Separate first-use, no-results and empty-page explanations. |
| Does this search cover every page? | Scope appeared only in filter help. | Node and server no-results states explicitly name the current page; their matching, loaded and total counts remain visible. |
| How can I recover from a bad bookmarked query? | Invalid parameters blocked the query with an error. | Users and orders offer a direct action to clear all list filters and restart on page one. |
| Why did a previously valid page become empty? | An empty table could resemble an empty inventory. | A return-to-first-page action retains the query and page size. |
| What should I do when starting out? | No next step within the empty list. | Users, nodes and servers offer the existing creation flow. Orders explain when purchase records appear. |

## Scope and recovery

- Users and orders query all pages through their current server-side contracts.
  Clearing all filters removes basic conditions, advanced conditions, sorting and
  the current page; it retains `per_page` and unrelated URL parameters.
- Nodes retain server-side `machine_id` selection and page-local text/status/
  protocol/ownership/visibility filters. Their empty message reflects whether
  page-local conditions are present. Clearing filters also clears node selection.
- Servers retain page-local filters. Matching counts never replace server totals
  or the separate all-server health overview.
- Empty-state creation buttons use the same permission, pending-operation and
  loading guards as the existing page actions. Errors and denied reads do not
  render the empty-state content or expose cached matching counts.
- This iteration adds no network-wide search operation, automatic retries,
  financial inference, or command behavior. Chinese, English and Russian copy
  cover the new guidance and actions.

## Verification

- `PaginationIntegration.test.tsx` covers invalid queries, advanced-filter
  clearing, preservation of unrelated URL state and page size, return from empty
  pages, and restoring locally filtered nodes/servers without a command.
- `scripts/admin-browser.mjs` exercises desktop node/account no-results recovery
  and mobile server no-results recovery, captures screenshots, and checks phone
  viewport bounds alongside the existing access and workflow assertions.
- Final test totals and exact-commit CI conclusions belong in the PR evidence;
  the implementation itself does not imply merge, image publication or deployment.
