---
name: LESta
description: A plain, dense, honest control panel for hosting customers and the providers who run them.
colors:
  ink: "oklch(0.145 0 0)"
  paper: "oklch(1 0 0)"
  graphite: "oklch(0.205 0 0)"
  near-white: "oklch(0.985 0 0)"
  chalk: "oklch(0.97 0 0)"
  hairline: "oklch(0.922 0 0)"
  control-edge: "oklch(0.64 0 0)"
  focus-ink: "oklch(0.556 0 0)"
  quiet-ink: "oklch(0.556 0 0)"
  alarm-red: "oklch(0.577 0.245 27.325)"
  status-ok-bg: "oklch(0.962 0.044 156.743)"
  status-ok-text: "oklch(0.527 0.154 150.069)"
  status-active-bg: "oklch(0.932 0.032 255.585)"
  status-active-text: "oklch(0.488 0.243 264.376)"
  status-degraded-bg: "oklch(0.962 0.059 95.617)"
  status-degraded-text: "oklch(0.555 0.163 48.998)"
  status-failed-bg: "oklch(0.936 0.032 17.717)"
  status-failed-text: "oklch(0.505 0.213 27.518)"
  status-idle-bg: "oklch(0.97 0 0)"
  status-idle-text: "oklch(0.371 0 0)"
typography:
  headline:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 600
    lineHeight: "1.75rem"
    letterSpacing: "-0.025em"
  section:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: "1.75rem"
  title:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 500
    lineHeight: "1.5rem"
  body:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: "1.25rem"
  label:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: "1rem"
rounded:
  sm: "6px"
  md: "8px"
  lg: "10px"
  xl: "12px"
  pill: "9999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.graphite}"
    textColor: "{colors.near-white}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-outline:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-small:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "0 12px"
    height: "32px"
  button-destructive:
    backgroundColor: "{colors.alarm-red}"
    textColor: "{colors.paper}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  input:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "4px 12px"
    height: "36px"
  card:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    rounded: "{rounded.xl}"
    padding: "24px"
  resource-table:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.xl}"
  badge-applied:
    backgroundColor: "{colors.status-ok-bg}"
    textColor: "{colors.status-ok-text}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "2px 8px"
  badge-in-progress:
    backgroundColor: "{colors.status-active-bg}"
    textColor: "{colors.status-active-text}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "2px 8px"
  badge-degraded:
    backgroundColor: "{colors.status-degraded-bg}"
    textColor: "{colors.status-degraded-text}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "2px 8px"
  badge-failed:
    backgroundColor: "{colors.status-failed-bg}"
    textColor: "{colors.status-failed-text}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "2px 8px"
  badge-idle:
    backgroundColor: "{colors.status-idle-bg}"
    textColor: "{colors.status-idle-text}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "2px 8px"
---

# Design System: LESta

## Overview

**Creative North Star: "The Plain Control Room"**

LESta looks like a control room that has nothing to prove: calm, legible, and exact about what state every resource is in. A hosting customer opens it to get one thing done, add a domain, a mailbox, a database, and then to see that it worked. The interface stays out of the way of that job and is never quiet about the outcome.

The voice is dense but readable, plainspoken, and technical. Lists are compact tables that stay scannable. Labels name things the way the work does (node, account, zone, mailbox) and messages say what happened. It is comfortable showing a hostname, a port or a five-field cron schedule as they are, because the people using it run real services.

The incumbent system is shadcn/ui's neutral kit, used as shipped: Instrument Sans, a 10px radius, hairline borders, and a palette with no hue of its own. That restraint is the point, and it is also the current extent of the design. Anything brand-specific, a logo, a signature color, has not been decided.

**Key Characteristics:**
- Ink on paper: near-black on white, reversed in dark mode, with color kept for meaning.
- Status as signal: green, blue, amber and red appear only to say what state something is in.
- Compact and exact: 36px controls, 32px small controls, 14px body text.
- Lightly layered: hairlines carry structure, soft shadows give a slight lift.
- Every state is visible: a change shows its provisioning status, and a refusal shows its reason.

## Colors

An achromatic scale with one error hue and a small, fixed set of status hues. Nothing is decorative.

### Primary
- **Graphite** (oklch(0.205 0 0)): the primary action fill, sidebar active mark, and selection. In dark mode the primary flips to near-white (oklch(0.985 0 0)) with graphite text on it.

### Neutral
- **Ink** (oklch(0.145 0 0)): body text and headings. Dark mode's page background.
- **Paper** (oklch(1 0 0)): page, card and popover surface. Dark mode uses ink here.
- **Near-white** (oklch(0.985 0 0)): text on graphite, and the sidebar surface in light mode.
- **Chalk** (oklch(0.97 0 0)): secondary, muted and hover surfaces, and the idle badge fill.
- **Hairline** (oklch(0.922 0 0)): every table border and divider.
- **Control edge** (oklch(0.64 0 0), 3.4:1 on white; oklch(0.52 0 0) in dark mode, 3.6:1): the border of every input, select and outline button, so a control's boundary is findable. Tables and dividers keep the lighter hairline.
- **Focus ink** (oklch(0.556 0 0)): the focus ring and focus border, solid and never translucent, 4.7:1 on white.
- **Quiet ink** (oklch(0.556 0 0)): secondary text, descriptions, table headers, placeholders.
- Dark mode counterparts: surface oklch(0.145 0 0), raised and muted surface oklch(0.269 0 0), border oklch(0.269 0 0), secondary text oklch(0.708 0 0), focus ring oklch(0.708 0 0), 7.9:1 on the page.

### Status and error
- **Alarm red** (oklch(0.577 0.245 27.325)): destructive buttons, invalid fields, error text, and "Suspended".
- **Applied green** (fill oklch(0.962 0.044 156.743), text oklch(0.527 0.154 150.069)): applied, already applied, and "Active" text in green-700 (oklch(0.527 0.154 150.069), 5.0:1 on white; green-400 in dark mode).
- **In-progress blue** (fill oklch(0.932 0.032 255.585), text oklch(0.488 0.243 264.376)): dispatched.
- **Degraded amber** (fill oklch(0.962 0.059 95.617), text oklch(0.555 0.163 48.998)): degraded.
- **Failed red** (fill oklch(0.936 0.032 17.717), text oklch(0.505 0.213 27.518)): rejected and failed.
- **Idle grey** (fill chalk, text oklch(0.371 0 0)): pending. "No operation yet" uses the same fill with text oklch(0.439 0 0), 7.2:1.

### Named Rules
**The Color Means Something Rule.** A hue appears only to report a state or an error, never to decorate. A screen with nothing wrong and nothing in flight is greyscale.

**The Four Statuses Rule.** Provisioning state is always one of the same badges: grey pending, blue dispatched, green applied, red failed or rejected, amber degraded. A new surface reuses them rather than inventing its own.

**The Quiet When Healthy Rule.** In a list read to find what is wrong (the admin customer lists), a healthy state is plain muted text ("Active", "Applied"), and only an exception earns color or a pill. A customer's own list, read to see that things worked, keeps the green.

## Typography

**Display Font:** Instrument Sans (with ui-sans-serif, system-ui, sans-serif)
**Body Font:** Instrument Sans, same stack
**Label/Mono Font:** Instrument Sans for labels; the browser's monospace for commands and schedules shown as code

**Character:** One humanist sans at three weights (400, 500, 600), served from Bunny Fonts. The hierarchy comes from size and weight, never from a second family. Code-like values (cron fields, commands) are set in monospace.

### Hierarchy
- **Headline** (600, 1.25rem, 1.75rem, tracking -0.025em): the page title, rendered as the page's h1, always paired with a one-line description in quiet ink.
- **Section** (600, 1.125rem, 1.75rem): a major group heading inside a page.
- **Title** (500 to 600, 1rem, 1.5rem): a small block heading, card titles, and the node heading in a customer list, followed by its muted 14px totals.
- **Body** (400, 0.875rem, 1.25rem): the default for tables, forms and descriptions. Form inputs are 1rem on small screens and 0.875rem from the medium breakpoint up.
- **Label** (500, 0.75rem, 1rem): table column headers in quiet ink, and badges.

### Named Rules
**The One Family Rule.** Instrument Sans is the only face for interface text. Monospace appears only for values the person may copy or type back, such as commands and cron fields.

## Layout

A fixed left sidebar, 16rem wide on desktop, collapsing to a 3rem icon rail, and an 18rem drawer on mobile. The content area holds a page padded 16px, vertical blocks spaced 24px (heading blocks 32px apart from content).

Lists and tables run the full content width. Forms and edit pages sit in a centered column capped at 48rem (max-w-3xl). Tables scroll horizontally inside their rounded border rather than shrinking text. Admin lists group by node, then account, with two filters above the first group.

Spacing follows a 4px base: 4, 8, 16, 24, 32. Controls sit 8px apart in a row; sections sit 24px apart.

## Elevation & Depth

Hybrid, leaning flat. Structure comes from 1px hairline borders and tonal surfaces; shadows are small and give only a slight lift.

### Shadow Vocabulary
- **Control lift** (`box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05)`): buttons and inputs at rest.
- **Card lift** (`box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)`): cards.
- **Focus ring** (`0 0 0 3px` in focus ink, solid, plus the border shifting to focus ink): any focusable control, on keyboard focus only.

### Named Rules
**The Hairline First Rule.** Separate things with a border before a shadow. A shadow never replaces a border, and nothing is lifted higher than a card.

## Shapes

Gently rounded, never pill-shaped except for status. The base radius is 10px (--radius), with 8px for buttons, inputs and selects, 6px for small elements, and 12px for cards and the rounded border around tables. Status badges are fully rounded. Checkboxes are 4px squares. Borders are always 1px.

## Components

### Buttons
Compact and exact.
- **Shape:** 8px radius, 36px high (32px small, 40px large), 16px horizontal padding, 14px medium text.
- **Primary:** graphite fill with near-white text, control lift shadow. Hover dims the fill to 90%.
- **Outline:** paper fill, hairline border, control lift. Hover fills with chalk. The default for secondary and row actions.
- **Destructive:** alarm red fill, white text, used for destructive actions and typically behind a confirming dialog.
- **Ghost / Link:** chalk fill on hover only; link variant underlines on hover.
- **Focus / Disabled:** the 3px focus ring; disabled is 50% opacity with pointer events off. An invalid state outlines in alarm red.

### Inputs / Fields
- **Style:** control-edge stroke, transparent fill, 8px radius, 36px high, 12px side padding, control lift.
- **Focus:** the border and a 3px solid ring shift to focus ink.
- **Error:** the stroke and a soft ring go alarm red, and a message in red text sits beneath the field.
- **Select and checkbox:** a select matches the input; a checkbox is a 16px, 4px-radius square that fills graphite when checked. Checkboxes always submit an explicit 1 or 0.
- **Search and filter:** every filter has a visible label above it. The account filter is a plain search input; node and status filters are selects.

### Resource Table
The signature container for every list: a 12px-radius hairline border around a full-width table.
- **Header:** 12px medium text in quiet ink over a bottom hairline.
- **Rows:** 8px by 16px cell padding, a hairline between rows and none after the last. The primary value is medium weight.
- **Columns, typically:** name, a few attributes, suspension (red "Suspended" or green "Active"), provisioning badge, actions.
- **Empty:** one centered quiet-ink row saying what is missing.

### Provisioning Badge
A fully rounded pill, 12px medium text, 2px by 8px padding, one of the five status fills. The label is the status in plain words ("Applied", "In progress", "Failed", "No operation yet"). One shared component renders it everywhere.

### Customer Resource List
The provider admin's read-only view. Above the list sit labelled filters (node, account, and a "Show: problems only" select), then one polite status line that announces what the list holds ("5 domains in 2 accounts on 2 nodes. Page 1 of 3", "Updating the list…") and, when nothing matches, says so and names the active filters. Below, one section per node: a node heading with its muted totals (and "n shown on this page" when a node continues onto the next page), then a single resource table.
- **Account column:** first and sticky, the owning account as a link (its id in muted text beneath, so same-named customers differ), repeated for assistive technology on following rows. Each table is a named, keyboard-focusable scroll region with a screen-reader caption.
- **Columns:** sortable ones are buttons in the header (ascending, descending, off) with `aria-sort`, and sort within each node. Numeric columns are right-aligned with tabular figures and grouped digits. Times are relative, with the full timestamp on hover.
- **Resource name:** a link to the resource's read-only detail page: its operational facts, sub-records (DNS records, mailboxes, recent cron runs) and recent provisioning operations with the node's reported reasons. Never credentials, payloads, cron output or mailbox content.
- **Status:** quiet text when healthy; a pill for an exception, with the node's reported reason beneath a failed one.
- **Pagination:** Previous and Next stay the same elements and become inert at the ends, so keyboard focus is never dropped.
- Rows carry no create, edit or delete actions, and a line says the view is read-only.

### Navigation
An inset sidebar (the content sits in a rounded panel beside it) of icon and label items, 14px, with 8px padding and an 8px radius. The active item takes a chalk fill and medium weight. Resource links come first (Dashboard, Domains, DNS, Mail, Databases, Cron jobs, Usage, Documentation), then, for a provider admin, Accounts, Nodes, Backups, Packages and Roles, and My account for anyone who also belongs to an account. A breadcrumb row sits above the page, and an impersonation banner appears above that while acting as another user.

### Dialogs and Toasts
Confirmations for destructive actions use a centered dialog with a muted description and a destructive primary action. Server refusals and flash messages appear as toasts at the bottom right, so a refused action always shows its reason.

## Do's and Don'ts

### Do:
- **Do** keep controls 36px high (32px when small) with 8px radius and a hairline border.
- **Do** reserve every hue for status or error, and reuse the five provisioning badges exactly.
- **Do** pair every page title with a one-line description in quiet ink, and render it as the page's h1.
- **Do** give every filter a visible label, and announce list changes in a polite live region.
- **Do** keep healthy states quiet in lists read for problems, and show a failure's reason next to it.
- **Do** show a visible result for every action: a status badge, an inline field error, or a toast.
- **Do** put structure in hairline borders first, shadows second.
- **Do** keep forms to a 48rem column and let tables use the full width and scroll sideways before wrapping.
- **Do** keep the contrast and focus behavior WCAG 2.2 AA requires: a visible 3px focus ring on every control, and text that meets contrast in both light and dark mode.

### Don't:
- **Don't** reproduce the dense icon grids and nested menus of cPanel and Plesk-style panels.
- **Don't** leave any control that fails or finishes without visible feedback.
- **Don't** add decorative color, gradients or illustration.
- **Don't** lift anything higher than a card, or use a shadow where a border would do.
- **Don't** introduce a second typeface, or size interface text below 12px.
- **Don't** offer create, edit or delete actions from a read-only admin list.
