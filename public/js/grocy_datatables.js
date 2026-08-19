// Default DataTables initialisation settings
var collapsedGroups = {};
$.extend(true, $.fn.dataTable.defaults, {
	'paginate': false,
	'deferRender': true,
	'language': IsJsonString(__t('datatables_localization')) ? JSON.parse(__t('datatables_localization')) : {},
	'scrollY': false,
	'scrollX': true,
	'colReorder': true,
	'stateSave': true,
	'stateDuration': 0,
	'stateSaveParams': function (settings, data)
	{
		data.search.search = "";

		data.columns.forEach(column =>
		{
			column.search.search = "";
		});
	},
	'stateSaveCallback': function (settings, data)
	{
		var settingKey = 'datatables_state_' + settings.sTableId;

		if ($.isEmptyObject(data))
		{
			// state.clear() was called (resetting table layout)
			Grocy.FrontendHelpers.DeleteUserSetting(settingKey, true);
		}
		else
		{
			// Don't save when the state data hasn't actually changed
			if (Grocy.UserSettings[settingKey] !== undefined)
			{
				var data1 = JSON.parse(Grocy.UserSettings[settingKey]);
				delete data1.time;
				delete data1.childRows;

				var data2 = Object.assign({}, data); // Clone `data` without reference
				delete data2.time;
				delete data2.childRows;

				if (JSON.stringify(data1) == JSON.stringify(data2))
				{
					return;
				}
			}

			Grocy.FrontendHelpers.SaveUserSetting(settingKey, JSON.stringify(data));
		}
	},
	'stateLoadCallback': function (settings, data)
	{
		var settingKey = 'datatables_state_' + settings.sTableId;

		if (Grocy.UserSettings[settingKey] == undefined)
		{
			return null;
		}
		else
		{
			return JSON.parse(Grocy.UserSettings[settingKey]);
		}
	},
	'preDrawCallback': function (settings)
	{
		// Currently it is not possible to save the state of rowGroup via saveState events
		var api = new $.fn.dataTable.Api(settings);
		if (typeof api.rowGroup === "function")
		{
			var settingKey = 'datatables_rowGroup_' + settings.sTableId;
			if (Grocy.UserSettings[settingKey] !== undefined)
			{
				var rowGroup = JSON.parse(Grocy.UserSettings[settingKey]);

				// The draw event is called often therefore we have to check if it's really necessary
				if (rowGroup.enable !== api.rowGroup().enabled()
					|| ("dataSrc" in rowGroup && rowGroup.dataSrc !== api.rowGroup().dataSrc()))
				{

					api.rowGroup().enable(rowGroup.enable);

					if ("dataSrc" in rowGroup)
					{
						api.rowGroup().dataSrc(rowGroup.dataSrc);

						// Apply fixed order for group column
						api.order.fixed({
							pre: [rowGroup.dataSrc, 'asc']
						});
					}
					else
					{
						// Remove fixed order
						api.order.fixed({});
					}
				}
			}
		}
	},
	'columnDefs': [
		{ type: 'string', targets: '_all' }
	],
	'rowGroup': {
		enable: false,
		startRender: function (rows, group)
		{
			var collapsed = !!collapsedGroups[group];
			var toggleClass = collapsed ? "fa-caret-right" : "fa-caret-down";

			rows.nodes().each(function (row)
			{
				row.style.display = collapsed ? "none" : "";
			});

			return $("<tr/>")
				.append('<td colspan="' + rows.columns()[0].length + '">' + group + ' <span class="fa fa-fw d-print-none ' + toggleClass + '"/></td>')
				.attr("data-name", group)
				.toggleClass("collapsed", collapsed);
		}
	}
});
$(document).on("click", "tr.dtrg-group", function ()
{
	var name = $(this).data('name');
	collapsedGroups[name] = !collapsedGroups[name];
	$("table").DataTable().draw();
});
$.fn.dataTable.ext.type.order["custom-sort-pre"] = function (data)
{
	// Workaround for https://github.com/DataTables/ColReorder/issues/85
	//
	// Custom sorting can normally be provided by a "data-order" attribute on the <td> element,
	// however this causes issues when reordering such a column...
	//
	// This here is for a custom column type "custom-sort",
	// the custom order value needs to be provided in the first child (<span>) of the <td>

	return (Number.parseFloat($(data).get(0).innerText));
};

$.fn.dataTable.ext.type.search.string = function (s)
{
	return s.accentNeutralise();
};
$.fn.dataTable.ext.type.search.html = function (s)
{
	return s.stripHtml().accentNeutralise();
};
$.fn.dataTable.ext.type.order["string-pre"] = function (s)
{
	return s.accentNeutralise();
};
$.fn.dataTable.ext.type.order["html-pre"] = function (s)
{
	return s.stripHtml().accentNeutralise();
};

$('.table').on('column-sizing.dt', function (e, settings)
{
	var dtScrollWidth = $('.dataTables_scroll').width();
	var tableWidth = $('.table').width() + 100; // Some extra padding, otherwise the scrollbar maybe only appears after a column is already completely out of the viewport

	if (dtScrollWidth < tableWidth)
	{
		$('.dataTables_scrollBody').addClass("no-force-overflow-visible");
		$('.dataTables_scrollBody').removeClass("force-overflow-visible");
	}
	else
	{
		$('.dataTables_scrollBody').removeClass("no-force-overflow-visible");
		$('.dataTables_scrollBody').addClass("force-overflow-visible");
	}
});
$(document).on("show.bs.dropdown", "td .dropdown", function (e)
{
	if ($('.dataTables_scrollBody').hasClass("no-force-overflow-visible"))
	{
		$('.dataTables_scrollBody').addClass("force-overflow-visible");
	}
});
$(document).on("hide.bs.dropdown", "td .dropdown", function (e)
{
	if ($('.dataTables_scrollBody').hasClass("no-force-overflow-visible"))
	{
		$('.dataTables_scrollBody').removeClass("force-overflow-visible");
	}
});

$(document).on("click", ".change-table-columns-visibility-button", function (e)
{
	e.preventDefault();

	var dataTableSelector = $(e.currentTarget).attr("data-table-selector");
	var dataTable = $(dataTableSelector).DataTable();

	var columnCheckBoxesHtml = "";
	var rowGroupRadioBoxesHtml = "";

	var rowGroupDefined = typeof dataTable.rowGroup === "function";

	if (rowGroupDefined)
	{
		var rowGroupChecked = (dataTable.rowGroup().enabled()) ? "" : "checked";
		rowGroupRadioBoxesHtml = ' \
			<div class="custom-control custom-radio custom-control-inline"> \
				<input ' + rowGroupChecked + ' class="custom-control-input change-table-columns-rowgroup-toggle" \
					type="radio" \
					name="column-rowgroup" \
					id="column-rowgroup-none" \
					data-table-selector="' + dataTableSelector + '" \
					data-column-index="-1" \
				> \
				<label class="custom-control-label font-italic" \
					for="column-rowgroup-none">' + __t("None") + ' \
				</label > \
			</div>';
	}

	dataTable.columns().every(function ()
	{
		var index = this.index();
		var indexForGrouping = index;
		var headerCell = $(this.header());
		var title = headerCell.text();
		var visible = this.visible();

		if (!title || title.trim().length == 0 || title.startsWith("Hidden") || headerCell.hasClass("d-none"))
		{
			return;
		}

		var shadowColumnIndex = headerCell.attr("data-shadow-rowgroup-column");
		if (shadowColumnIndex)
		{
			indexForGrouping = shadowColumnIndex;
		}

		var checked = "checked";
		if (!visible)
		{
			checked = "";
		}

		columnCheckBoxesHtml += ' \
			<div class="custom-control custom-checkbox"> \
				<input ' + checked + ' class="form-check-input custom-control-input change-table-columns-visibility-toggle" \
					type="checkbox" \
					id="column-' + index.toString() + '" \
					data-table-selector="' + dataTableSelector + '" \
					data-column-index="' + index.toString() + '" \
					value="1"> \
				<label class="form-check-label custom-control-label" \
					for="column-' + index.toString() + '">' + title + ' \
				</label> \
			</div>';

		if (rowGroupDefined && headerCell.hasClass("allow-grouping"))
		{
			var rowGroupChecked = "";
			if (dataTable.rowGroup().enabled() && dataTable.rowGroup().dataSrc() == index)
			{
				rowGroupChecked = "checked";
			}

			rowGroupRadioBoxesHtml += ' \
			<div class="custom-control custom-radio"> \
				<input ' + rowGroupChecked + ' class="custom-control-input change-table-columns-rowgroup-toggle" \
					type="radio" \
					name="column-rowgroup" \
					id="column-rowgroup-' + indexForGrouping.toString() + '" \
					data-table-selector="' + dataTableSelector + '" \
					data-column-index="' + indexForGrouping.toString() + '" \
				> \
				<label class="custom-control-label" \
					for="column-rowgroup-' + indexForGrouping.toString() + '">' + title + ' \
				</label > \
			</div>';
		}
	});

	var message = '\
		<div class="text-center"> \
			<h5>' + __t('Table options') + '</h5> \
			<hr> \
			<h5 class="mb-0">' + __t('Hide/view columns') + '</h5> \
			<div class="text-left form-group"> \
				' + columnCheckBoxesHtml + ' \
			</div> \
		</div>';

	if (rowGroupDefined)
	{
		message += ' \
			<div class="text-center mt-1"> \
				<h5 class="pt-3 mb-0">' + __t('Group by') + '</h5> \
				<div class="text-left form-group"> \
					' + rowGroupRadioBoxesHtml + ' \
				</div> \
			</div>';
	}

	bootbox.dialog({
		message: message,
		size: 'small',
		backdrop: true,
		closeButton: false,
		buttons: {
			reset: {
				label: __t('Reset'),
				className: 'btn-outline-danger float-left responsive-button',
				callback: function ()
				{
					bootbox.confirm({
						message: __t("Are you sure you want to reset the table options?"),
						closeButton: false,
						buttons: {
							cancel: {
								label: 'No',
								className: 'btn-danger'
							},
							confirm: {
								label: 'Yes',
								className: 'btn-success'
							}
						},
						callback: function (result)
						{
							if (result)
							{
								var dataTable = $(dataTableSelector).DataTable();
								var tableId = dataTable.settings()[0].sTableId;

								// Delete rowgroup settings
								Grocy.FrontendHelpers.DeleteUserSetting('datatables_rowGroup_' + tableId);

								// Delete state settings
								dataTable.state.clear();
							}
							$(".modal").last().modal("hide");
						}
					});
				}
			},
			ok: {
				label: __t('OK'),
				className: 'btn-primary responsive-button',
				callback: function ()
				{
					$(".modal").last().modal("hide");
				}
			}
		}
	});
});

$(document).on("click", ".change-table-columns-visibility-toggle", function ()
{
	var dataTableSelector = $(this).attr("data-table-selector");
	var columnIndex = $(this).attr("data-column-index");
	var dataTable = $(dataTableSelector).DataTable();

	dataTable.columns(columnIndex).visible(this.checked);
});


$(document).on("click", ".change-table-columns-rowgroup-toggle", function ()
{
	var dataTableSelector = $(this).attr("data-table-selector");
	var columnIndex = $(this).attr("data-column-index");
	var dataTable = $(dataTableSelector).DataTable();
	var rowGroup;

	if (columnIndex == -1)
	{
		rowGroup = {
			enable: false
		};

		dataTable.rowGroup().enable(false);

		// Remove fixed order
		dataTable.order.fixed({});
	}
	else
	{
		rowGroup = {
			enable: true,
			dataSrc: columnIndex
		}

		dataTable.rowGroup().enable(true);
		dataTable.rowGroup().dataSrc(columnIndex);

		// Apply fixed order for group column
		dataTable.order.fixed({
			pre: [columnIndex, 'asc']
		});
	}

	var settingKey = 'datatables_rowGroup_' + dataTable.settings()[0].sTableId;
	Grocy.FrontendHelpers.SaveUserSetting(settingKey, JSON.stringify(rowGroup));

	dataTable.draw();
});

// ---------------------------------------------------------------------------
// Mobile card mode
// ---------------------------------------------------------------------------
// On small screens a wide table is unusable, so every DataTable is re-flowed
// into a list of cards (see grocy_mobile.css). All the CSS needs is a label per
// cell plus a marker for the headline and the action cell - both are derived
// from the column headers here, so no view has to be adjusted.

var GrocyDataTablesCardMode = {
	Breakpoint: 768
};

GrocyDataTablesCardMode.IsActive = function ()
{
	return window.innerWidth < GrocyDataTablesCardMode.Breakpoint;
};

// The captions of all currently visible columns, in the same order as the
// cells of a body row (invisible columns are removed from the DOM by DataTables)
GrocyDataTablesCardMode.VisibleColumns = function (api)
{
	var columns = [];

	// Note: the ":visible" column selector isn't supported by the bundled
	// DataTables version, so filter manually
	api.columns().every(function ()
	{
		if (!this.visible())
		{
			return;
		}

		columns.push({
			index: this.index(),
			header: $(this.header()),
			title: $(this.header()).text().replace(/\s+/g, " ").trim()
		});
	});

	return columns;
};

// Buttons, dropdowns and button groups - not the tooltips and icons inside
GrocyDataTablesCardMode.CountControls = function (cell)
{
	var controls = cell.querySelectorAll(":scope > .btn, :scope > .dropdown, :scope > .btn-group");
	var count = 0;

	for (var i = 0; i < controls.length; i++)
	{
		// Whatever the view hides stays hidden in card mode as well
		if (!controls[i].classList.contains("d-none") && window.getComputedStyle(controls[i]).display !== "none")
		{
			count++;
		}
	}

	return count;
};

GrocyDataTablesCardMode.Apply = function (api)
{
	var columns = GrocyDataTablesCardMode.VisibleColumns(api);

	var headers = columns.map(function (column)
	{
		return column.title;
	});

	// Several views carry columns that DataTables considers visible but that
	// are hidden with `d-none` (e.g. "Hidden product_id" in the stock entries
	// table). They must not become the headline - that would leave the card
	// without one.
	var titleIndex = columns.findIndex(function (column)
	{
		return column.title.length > 0 && !column.header.hasClass("d-none");
	});

	var body = api.table().body();

	if (!body)
	{
		return;
	}

	var rows = body.children;

	for (var r = 0; r < rows.length; r++)
	{
		var cells = rows[r].children;

		// Row group headers and the "no data" row don't map to columns
		if (cells.length !== headers.length)
		{
			continue;
		}

		for (var c = 0; c < cells.length; c++)
		{
			var cell = cells[c];
			var label = headers[c];

			cell.setAttribute("data-label", label);
			cell.classList.toggle("dt-cell-actions", label.length === 0);
			cell.classList.toggle("dt-cell-title", c === titleIndex);

			// The action cell floats in the top right corner of the card,
			// which only works for one or two controls - more than that and
			// they end up sitting on the card's first line. Those cells get a
			// row of their own instead.
			if (label.length === 0)
			{
				var controls = GrocyDataTablesCardMode.CountControls(cell);
				var floating = controls <= 2;

				cell.classList.toggle("dt-cell-actions-row", !floating);

				// A floating corner still covers the end of the headline, so
				// the card reserves exactly as much room as it needs - a long
				// product name then wraps instead of running underneath it
				if (floating)
				{
					rows[r].style.setProperty("--g-actions-w", (controls * 30 + (controls - 1) * 4 + 10) + "px");
				}
				else
				{
					rows[r].style.removeProperty("--g-actions-w");
				}
			}
			cell.classList.toggle("dt-cell-empty", label.length !== 0
				&& c !== titleIndex
				&& cell.children.length === 0
				&& cell.textContent.trim().length === 0);
		}
	}
};

// A hidden <thead> also hides the table options and the sort handles, so both
// get a dedicated toolbar above the cards
GrocyDataTablesCardMode.AddToolbar = function (api)
{
	var tableNode = api.table().node();
	var wrapper = $(api.table().container());

	if (wrapper.find("> .dt-card-toolbar").length > 0)
	{
		return;
	}

	var tableSelector = "#" + tableNode.id;
	var hasOptionsButton = $(tableNode).find("thead .change-table-columns-visibility-button").length > 0
		|| $(api.table().header()).find(".change-table-columns-visibility-button").length > 0;

	var toolbar = $('<div class="dt-card-toolbar d-md-none"></div>');

	toolbar.append('\
		<div class="dropdown"> \
			<button class="btn btn-sm btn-outline-dark dropdown-toggle dt-sort-button" type="button" data-toggle="dropdown"> \
				<i class="fa-solid fa-arrow-down-short-wide"></i> ' + __t("Sort by") + ' \
			</button> \
			<div class="dropdown-menu dt-sort-menu"></div> \
		</div>');

	if (hasOptionsButton && tableNode.id)
	{
		toolbar.append('\
			<button class="btn btn-sm btn-outline-dark change-table-columns-visibility-button" type="button" data-table-selector="' + tableSelector + '"> \
				<i class="fa-solid fa-eye"></i> ' + __t("Table options") + ' \
			</button>');
	}

	wrapper.prepend(toolbar);
};

GrocyDataTablesCardMode.BuildSortMenu = function (api, menu)
{
	var order = api.order();
	var currentColumn = (order.length > 0) ? order[0][0] : -1;
	var currentDirection = (order.length > 0) ? order[0][1] : "asc";
	var html = "";

	GrocyDataTablesCardMode.VisibleColumns(api).forEach(function (column)
	{
		var index = column.index;
		var header = column.header;
		var title = column.title;

		if (title.length === 0 || title.startsWith("Hidden") || header.hasClass("d-none"))
		{
			return;
		}

		var isCurrent = (index === currentColumn);
		var nextDirection = (isCurrent && currentDirection === "asc") ? "desc" : "asc";
		var icon = "fa-arrow-down-a-z";

		if (isCurrent)
		{
			icon = (currentDirection === "asc") ? "fa-arrow-down-a-z" : "fa-arrow-up-a-z";
		}

		html += '<a class="dropdown-item dt-sort-item' + (isCurrent ? " active" : "") + '" href="#" \
			data-column-index="' + index + '" data-direction="' + nextDirection + '"> \
			<i class="fa-solid fa-fw ' + (isCurrent ? icon : "fa-arrow-right-arrow-left fa-rotate-90") + '"></i>&nbsp;' + title + '</a>';
	});

	menu.html(html);
};

$(document).on("show.bs.dropdown", ".dt-card-toolbar .dropdown", function ()
{
	var menu = $(this).find(".dt-sort-menu");
	var table = $(this).closest(".dataTables_wrapper").find("table.dataTable").first();

	if (table.length === 0)
	{
		return;
	}

	GrocyDataTablesCardMode.BuildSortMenu(table.DataTable(), menu);
});

$(document).on("click", ".dt-sort-item", function (e)
{
	e.preventDefault();

	var table = $(this).closest(".dataTables_wrapper").find("table.dataTable").first();

	if (table.length === 0)
	{
		return;
	}

	table.DataTable().order([parseInt($(this).attr("data-column-index")), $(this).attr("data-direction")]).draw();
});

$(document).on("init.dt", function (e, settings)
{
	var api = new $.fn.dataTable.Api(settings);

	$(api.table().node()).addClass("dt-cards");
	GrocyDataTablesCardMode.AddToolbar(api);
});

$(document).on("draw.dt", function (e, settings)
{
	if (!GrocyDataTablesCardMode.IsActive())
	{
		return;
	}

	GrocyDataTablesCardMode.Apply(new $.fn.dataTable.Api(settings));
});

(function ()
{
	var resizeTimeout = null;
	var wasCardMode = GrocyDataTablesCardMode.IsActive();

	$(window).on("resize", function ()
	{
		window.clearTimeout(resizeTimeout);

		resizeTimeout = window.setTimeout(function ()
		{
			var isCardMode = GrocyDataTablesCardMode.IsActive();

			if (isCardMode === wasCardMode)
			{
				return;
			}

			wasCardMode = isCardMode;

			$("table.dt-cards").each(function ()
			{
				var api = $(this).DataTable();

				if (isCardMode)
				{
					GrocyDataTablesCardMode.Apply(api);
				}
				else
				{
					// Column widths were calculated while the table was
					// rendered as cards - recalculate them for the grid layout
					api.columns.adjust();
				}
			});
		}, 150);
	});
})();
