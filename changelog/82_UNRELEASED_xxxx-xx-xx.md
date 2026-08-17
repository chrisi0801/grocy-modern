> ⚠️ xxxBREAKING CHANGESxxx

> ❗ xxxImportant upgrade informationXXX

> 💡 xxxMinor upgrade informationXXX

### New Feature: xxxx

- xxx

### Stock

- The product picker now searches product names accent insensitive
- Optimized the location input on the transfer page: The selected "From Location" is now automatically hidden in the "To Location" dropdown
- Fixed that the product picker workflow dialog was not displayed when the entered value contained double quotes
- Fixed that changing the location on the purchase page re-initialized the due date based on product defaults (if any)
- Fixed that when undoing a product consume or transfer transaction, the store of the corresponding stock entry wasn't restored
  - This will only apply to new consume / transfer transactions, not when undoing transactions made before using this release
- Fixed that the status filter on the master data products page always displayed "All" after selection (only affected Chrome/Edge)
- Fixed that the "This means _n QU_ will be removed/added from stock"-hint on the inventory page wasn't updated when changing the quantity unit only
- Fixed that the product open button on the stock overview page wasn't disabled after opening the last unit

### Shopping list

- Fixed that the shopping list setting (top right corner settings menu) "Round up quantity amounts to the nearest whole number" wasn't applied to shopping list item amounts where a quantity unit conversion was involved
- Fixed that printing the shopping list with "Group by product group" enabled created duplicated product group headlines in some cases

### Recipes

- Fixed that the ingredient list showed fixed "Calories" instead of the configured `ENERGY_UNIT`

### Meal plan

- Fixed that "add recipe"-dropdown wasn't sorted alphabetically

### Chores

- xxx

### Calendar

- xxx

### Tasks

- Added a table filter for "Assigned to"

### Batteries

- xxx

### Equipment

- xxx

### Userfields

- Fixed that Userfields of type "Select list (a single item can be selected)" changed by keyboard only were not saved

### General

- Completely reworked the user interface: modern, mobile first design
  - New design system: all colors, radii, elevations and typography are driven by CSS custom properties (`/public/css/grocy_theme.css`), so re-theming (also via `custom_css.html`) only means overriding a handful of variables
  - New app shell: sticky top bar, a quieter sidebar which can be collapsed to an icon rail, and on phones/tablets a proper off-canvas navigation drawer (swipe or tap outside to close) instead of the menu pushing the page down
  - New bottom tab bar on phones/tablets with the most used views plus a quick action button for purchase / consume / transfer / inventory
  - Tables are rendered as cards on small screens: every cell is labelled with its column header, the row actions move into a footer, empty cells are omitted and a toolbar above the list offers sorting and the table options (both were only reachable via the table header before)
  - Dialogs are shown as bottom sheets on phones
  - Dark mode was rebuilt on top of the same design tokens, so it now covers all components consistently
  - Toasts are collapsed to a single line and expand on tap to reveal the full text and the "Undo" button, instead of covering a good part of a phone screen for 20 seconds
  - The stock overview row actions are down to the quick consume button plus the overflow menu: "Consume all" and "Mark as opened" moved into that menu, which also shrinks the action column on desktop
- Fixed accent insensitive searching using the general table search field was broken
- Fixed that it wasn't possible to log in using passwords containing special escape sequences (e.g. `<<`)
- Fixed that the initially created location and quantity units weren't localized (only applies to new installations)

### API

- Fixed that the endpoints `POST /stock/shoppinglist/add-product` and `POST /stock/shoppinglist/remove-product` truncated decimal product amounts
