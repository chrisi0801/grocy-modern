// Barcode lookup (Grocy Modern) - the dialog behind "E" in the product
// picker: searches the free sources, offers the web search with AI when they
// know nothing, and shows what it found as a preview to confirm or correct.
// See services/BarcodeLookupService.php.

var GrocyBarcodeLookup = {
	Dialog: null,
	State: null
};

GrocyBarcodeLookup.Escape = function (text)
{
	return $("<div></div>").text(text === null || text === undefined ? "" : String(text)).html();
};

GrocyBarcodeLookup.ErrorText = function (xhr)
{
	try
	{
		var response = JSON.parse(xhr.response);

		if (response && response.error_message)
		{
			return response.error_message;
		}
	}
	catch (e)
	{
	}

	return __t("The barcode lookup failed");
};

// barcode: what was scanned; returnFlow: the query string the page needs to
// pick the new product up again (embedded, list, ...)
GrocyBarcodeLookup.Open = function (barcode, returnFlow)
{
	GrocyBarcodeLookup.State = { barcode: barcode, returnFlow: returnFlow || "", data: null };

	GrocyBarcodeLookup.Dialog = bootbox.dialog({
		title: '<i class="fa-solid fa-barcode"></i> ' + GrocyBarcodeLookup.Escape(barcode),
		message: '<div id="barcode-lookup-body"></div>',
		className: "barcode-lookup-dialog",
		onEscape: true,
		backdrop: true,
		closeButton: true,
		buttons: {}
	});

	GrocyBarcodeLookup.Dialog.on("hidden.bs.modal", function ()
	{
		GrocyBarcodeLookup.Dialog = null;
		setTimeout(function ()
		{
			if (Grocy.Components.ProductPicker)
			{
				Grocy.Components.ProductPicker.GetInputElement().focus().select();
			}
		}, Grocy.FormFocusDelay);
	});

	GrocyBarcodeLookup.ShowProgress([
		__t("Your other profiles"),
		__t("Open Food Facts, Open Beauty Facts, Open Products Facts")
	]);

	Grocy.Api.Get("barcodelookup/lookup/" + encodeURIComponent(barcode),
		function (data)
		{
			GrocyBarcodeLookup.Show(data);
		},
		function (xhr)
		{
			GrocyBarcodeLookup.ShowMessage(GrocyBarcodeLookup.ErrorText(xhr), true);
		}
	);
};

GrocyBarcodeLookup.Body = function ()
{
	return $("#barcode-lookup-body");
};

GrocyBarcodeLookup.SetButtons = function (buttons)
{
	var footer = GrocyBarcodeLookup.Dialog.find(".modal-footer");

	if (footer.length === 0)
	{
		footer = $('<div class="modal-footer"></div>').appendTo(GrocyBarcodeLookup.Dialog.find(".modal-content"));
	}

	footer.empty();
	buttons.forEach(function (button)
	{
		$('<button type="button" class="btn"></button>')
			.addClass(button.className)
			.html(button.label)
			.on("click", button.callback)
			.appendTo(footer);
	});
};

GrocyBarcodeLookup.Close = function ()
{
	if (GrocyBarcodeLookup.Dialog)
	{
		GrocyBarcodeLookup.Dialog.modal("hide");
	}
};

GrocyBarcodeLookup.ShowProgress = function (steps, note)
{
	var html = '<ul class="barcode-lookup-steps">';
	steps.forEach(function (step)
	{
		html += '<li><i class="fa-solid fa-spinner fa-spin"></i> ' + GrocyBarcodeLookup.Escape(step) + "</li>";
	});
	html += "</ul>";

	if (note)
	{
		html += '<p class="barcode-lookup-note">' + GrocyBarcodeLookup.Escape(note) + "</p>";
	}

	GrocyBarcodeLookup.Body().html(html);
	GrocyBarcodeLookup.SetButtons([{ label: __t("Cancel"), className: "btn-secondary", callback: GrocyBarcodeLookup.Close }]);
};

GrocyBarcodeLookup.ShowMessage = function (text, isError)
{
	GrocyBarcodeLookup.Body().html('<p class="' + (isError ? "text-danger" : "") + '">' + GrocyBarcodeLookup.Escape(text) + "</p>");
	GrocyBarcodeLookup.ShowManualOptions(GrocyBarcodeLookup.State.data);
};

// What Claude said about its search, and what it cost
GrocyBarcodeLookup.AiReport = function (data)
{
	var html = "";

	if (data.ai_note)
	{
		html += '<p class="barcode-lookup-ai-note' + (data.ai_problem ? " text-danger" : "") + '"><i class="fa-solid fa-comment-dots"></i> ' + GrocyBarcodeLookup.Escape(data.ai_note) + "</p>";
	}

	if (data.ai_cost !== undefined)
	{
		html += '<p class="barcode-lookup-note barcode-lookup-cost">' + __t("%1$s, about %2$s cents", GrocyBarcodeLookup.Escape(data.ai_model), (data.ai_cost * 100).toLocaleString(undefined, { maximumFractionDigits: 1 })) + "</p>";
	}

	return html;
};

// The paid search: what it does, an optional hint from the package, the
// button to start it
GrocyBarcodeLookup.AiOffer = function (ai, again)
{
	return '<p class="barcode-lookup-note">' + (again
		? __t("With a hint from the package - for example brand and product name - the AI can search for the product itself and check its barcode.")
		: __t("The web search with AI (Claude) looks for the barcode in online shops and product databases. It costs a few cents and takes up to a minute - %s searches left this month.", ai.remaining)) + "</p>"
		+ '<div class="form-group mb-0"><label for="barcode-lookup-ai-hint">' + __t("What does the package say? (optional)") + "</label>"
		+ '<input type="text" class="form-control" id="barcode-lookup-ai-hint" maxlength="200" autocomplete="off" placeholder="' + GrocyBarcodeLookup.Escape(__t("e.g. Hakle feuchtes Toilettenpapier")) + '"></div>';
};

// Nothing found anywhere free: the paid search, or by hand as before
GrocyBarcodeLookup.ShowNotFound = function (data, afterAi)
{
	var ai = data.ai;
	var html = '<div class="barcode-lookup-empty"><i class="fa-solid fa-magnifying-glass"></i><div>'
		+ (afterAi
			? __t("The web search did not find a product for this barcode either.")
			: __t("Neither your other profiles nor the Open Facts databases know this barcode."))
		+ "</div></div>";

	if (afterAi)
	{
		html += GrocyBarcodeLookup.AiReport(data);
	}

	if (ai.available)
	{
		html += GrocyBarcodeLookup.AiOffer(ai, afterAi);
	}
	else if (ai.reason === "limit")
	{
		html += '<p class="barcode-lookup-note">' + __t("The monthly limit for web searches with AI is reached") + "</p>";
	}

	GrocyBarcodeLookup.Body().html(html);
	GrocyBarcodeLookup.ShowManualOptions(data, ai.available, afterAi);
};

// "Search with AI instead" on a preview: the offer, with room for a hint
GrocyBarcodeLookup.ShowAiOffer = function ()
{
	var data = GrocyBarcodeLookup.State.data;
	GrocyBarcodeLookup.Body().html(GrocyBarcodeLookup.AiOffer(data.ai, false));
	GrocyBarcodeLookup.ShowManualOptions(data, true, false);
};

GrocyBarcodeLookup.ShowManualOptions = function (data, offerAi, again)
{
	var buttons = [
		{ label: __t("Cancel"), className: "btn-secondary", callback: GrocyBarcodeLookup.Close },
		{
			label: '<i class="fa-solid fa-pen"></i> ' + __t("Create manually"),
			className: "btn-outline-dark",
			callback: function ()
			{
				var state = GrocyBarcodeLookup.State;
				window.location.href = U("/product/new?flow=InplaceNewProductWithBarcode&barcode=" + encodeURIComponent(state.barcode) + "&returnto=" + encodeURIComponent(Grocy.CurrentUrlRelative + "?flow=InplaceAddBarcodeToExistingProduct&barcode=" + state.barcode + "&" + state.returnFlow) + "&" + state.returnFlow);
			}
		}
	];

	if (offerAi)
	{
		buttons.push({
			label: '<i class="fa-solid fa-wand-magic-sparkles"></i> ' + (again ? __t("Search again") : __t("Search the web with AI")),
			className: "btn-primary",
			callback: GrocyBarcodeLookup.SearchWithAi
		});
	}

	GrocyBarcodeLookup.SetButtons(buttons);
	$("#barcode-lookup-ai-hint").on("keydown", function (e)
	{
		if (e.key === "Enter")
		{
			e.preventDefault();
			GrocyBarcodeLookup.SearchWithAi();
		}
	});
};

GrocyBarcodeLookup.SearchWithAi = function ()
{
	var hint = ($("#barcode-lookup-ai-hint").val() || "").trim();
	GrocyBarcodeLookup.ShowProgress([__t("Claude is searching the web")], __t("This can take up to a minute."));

	Grocy.Api.Post("barcodelookup/lookup/" + encodeURIComponent(GrocyBarcodeLookup.State.barcode) + "/ai", { "hint": hint },
		function (data)
		{
			GrocyBarcodeLookup.Show(data, true);
		},
		function (xhr)
		{
			GrocyBarcodeLookup.ShowMessage(GrocyBarcodeLookup.ErrorText(xhr), true);
		}
	);
};

GrocyBarcodeLookup.Select = function (id, label, rows, selectedId, allowEmpty)
{
	var html = '<div class="form-group"><label for="' + id + '">' + GrocyBarcodeLookup.Escape(label) + "</label>"
		+ '<select class="custom-control custom-select" id="' + id + '">';

	if (allowEmpty)
	{
		html += '<option value=""></option>';
	}

	rows.forEach(function (row)
	{
		html += '<option value="' + row.id + '"' + (Number(row.id) === Number(selectedId) ? " selected" : "") + ">" + GrocyBarcodeLookup.Escape(row.name) + "</option>";
	});

	return html + "</select></div>";
};

GrocyBarcodeLookup.Show = function (data, afterAi)
{
	GrocyBarcodeLookup.State.data = data;

	if (!GrocyBarcodeLookup.Dialog)
	{
		return;
	}

	if (!data.result)
	{
		GrocyBarcodeLookup.ShowNotFound(data, afterAi);
		return;
	}

	var result = data.result;
	var picture = "";

	// A picture from another profile can't be shown from here (it lives in
	// that profile's files) - it is copied along when the product is created
	if (result.image_url && result.image_url !== "profile")
	{
		picture = '<img class="barcode-lookup-picture" alt="" referrerpolicy="no-referrer" src="' + GrocyBarcodeLookup.Escape(result.image_url) + '" onerror="this.remove()">';
	}

	var source = '<span class="barcode-lookup-source source-' + result.source + '">' + GrocyBarcodeLookup.Escape(result.source_label) + "</span>";

	if (result.source === "ai")
	{
		var confidence = { "high": __t("certain"), "medium": __t("probable"), "low": __t("uncertain") }[result.confidence] || result.confidence;
		source += ' <span class="barcode-lookup-confidence confidence-' + result.confidence + '">' + GrocyBarcodeLookup.Escape(confidence) + "</span>";
	}

	var html = '<div class="barcode-lookup-head">' + picture + '<div class="barcode-lookup-head-text">' + source;

	if (result.package_size)
	{
		html += '<div class="barcode-lookup-size"><i class="fa-solid fa-box-open"></i> ' + GrocyBarcodeLookup.Escape(result.package_size) + "</div>";
	}

	if (result.source_url)
	{
		html += '<div class="barcode-lookup-link"><a href="' + GrocyBarcodeLookup.Escape(result.source_url) + '" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-arrow-up-right-from-square"></i> ' + __t("Source") + "</a></div>";
	}

	html += "</div></div>";

	if (afterAi)
	{
		html += GrocyBarcodeLookup.AiReport(data);
	}

	if (result.existing_product)
	{
		html += '<div class="alert alert-info barcode-lookup-existing">' + __t('There already is a product "%s". Perhaps this is just another barcode for it?', GrocyBarcodeLookup.Escape(result.existing_product.name))
			+ '<br><button type="button" class="btn btn-sm btn-info mt-2" id="barcode-lookup-add-to-existing">' + __t('Add the barcode to "%s"', GrocyBarcodeLookup.Escape(result.existing_product.name)) + "</button></div>";
	}

	html += '<form id="barcode-lookup-form" novalidate>'
		+ '<div class="form-group"><label for="barcode-lookup-name">' + __t("Name") + "</label>"
		+ '<input type="text" class="form-control" id="barcode-lookup-name" required maxlength="200" value="' + GrocyBarcodeLookup.Escape(result.name) + '">'
		+ '<div class="invalid-feedback">' + __t("A name is required") + "</div></div>"
		+ GrocyBarcodeLookup.Select("barcode-lookup-group", __t("Product group"), data.options.product_groups, result.product_group_id, true)
		+ '<div class="form-row"><div class="col-6">' + GrocyBarcodeLookup.Select("barcode-lookup-location", __t("Default location"), data.options.locations, result.location_id, false) + "</div>"
		+ '<div class="col-6">' + GrocyBarcodeLookup.Select("barcode-lookup-qu", __t("Quantity unit"), data.options.quantity_units, result.qu_id, false) + "</div></div>"
		+ '<small class="form-text text-muted barcode-lookup-qu-hint">' + __t('Stock is counted in this unit. To change it after the first purchase, use "Count stock in another unit" on the product.') + "</small>"
		+ '<div class="form-row mt-3"><div class="col-6"><div class="form-group">'
		+ '<label for="barcode-lookup-min-stock">' + __t("Minimum stock amount") + "</label>"
		+ '<input type="number" class="form-control" id="barcode-lookup-min-stock" inputmode="decimal" min="0" step="any" value="' + GrocyBarcodeLookup.Escape(result.min_stock_amount || 0) + '">'
		+ '<div class="invalid-feedback">' + __t("This cannot be negative") + "</div></div></div>"
		+ '<div class="col-6"><div class="form-group">'
		+ '<label for="barcode-lookup-due-days">' + __t("Keeps for (days)") + "</label>"
		+ '<input type="number" class="form-control" id="barcode-lookup-due-days" inputmode="numeric" min="0" step="1">'
		+ '<div class="custom-control custom-checkbox mt-1"><input type="checkbox" class="custom-control-input" id="barcode-lookup-never-overdue">'
		+ '<label class="custom-control-label" for="barcode-lookup-never-overdue">' + __t("Never overdue") + "</label></div>"
		+ "</div></div></div>"
		+ '<small class="form-text text-muted barcode-lookup-due-hint">' + __t("With 0 days the due date is asked for on every purchase.") + "</small>"
		+ "</form>";

	if (result.learned)
	{
		html += '<p class="barcode-lookup-note"><i class="fa-solid fa-lightbulb"></i> ' + __t("Group, location and unit as chosen last time for this kind of product.") + "</p>";
	}

	GrocyBarcodeLookup.Body().html(html);
	GrocyBarcodeLookup.SetDueDays(result.default_best_before_days);
	GrocyBarcodeLookup.FollowGroup(data.group_defaults || {});

	var buttons = [{ label: __t("Cancel"), className: "btn-secondary", callback: GrocyBarcodeLookup.Close }];

	// Not what was meant? The web search may still know better
	if (!afterAi && data.ai.available)
	{
		buttons.push({
			label: '<i class="fa-solid fa-wand-magic-sparkles"></i> ' + __t("Search with AI instead"),
			className: "btn-outline-dark",
			callback: GrocyBarcodeLookup.ShowAiOffer
		});
	}

	buttons.push({ label: '<i class="fa-solid fa-check"></i> ' + __t("Create product"), className: "btn-success", callback: GrocyBarcodeLookup.Create });
	GrocyBarcodeLookup.SetButtons(buttons);
};

// -1 is grocy's "never overdue", 0 asks for the date on purchase
GrocyBarcodeLookup.SetDueDays = function (days)
{
	days = parseInt(days, 10) || 0;
	$("#barcode-lookup-never-overdue").prop("checked", days === -1);
	$("#barcode-lookup-due-days").val(days === -1 ? "" : days).prop("disabled", days === -1);
};

GrocyBarcodeLookup.DueDays = function ()
{
	return $("#barcode-lookup-never-overdue").prop("checked") ? -1 : Math.max(0, parseInt($("#barcode-lookup-due-days").val(), 10) || 0);
};

// Choosing another group brings that group's usual location, unit and due
// days along - unless they were already set by hand
GrocyBarcodeLookup.FollowGroup = function (groupDefaults)
{
	var touched = {};

	$("#barcode-lookup-location, #barcode-lookup-qu, #barcode-lookup-due-days, #barcode-lookup-never-overdue").on("change", function ()
	{
		touched[this.id] = true;
	});

	$("#barcode-lookup-never-overdue").on("change", function ()
	{
		$("#barcode-lookup-due-days").prop("disabled", this.checked);
	});

	$("#barcode-lookup-group").on("change", function ()
	{
		var defaults = groupDefaults[$(this).val()];

		if (!defaults)
		{
			return;
		}

		if (!touched["barcode-lookup-location"] && defaults.location_id && $('#barcode-lookup-location option[value="' + defaults.location_id + '"]').length)
		{
			$("#barcode-lookup-location").val(defaults.location_id);
		}

		if (!touched["barcode-lookup-qu"] && defaults.qu_id && $('#barcode-lookup-qu option[value="' + defaults.qu_id + '"]').length)
		{
			$("#barcode-lookup-qu").val(defaults.qu_id);
		}

		if (!touched["barcode-lookup-due-days"] && !touched["barcode-lookup-never-overdue"] && defaults.default_best_before_days !== undefined && defaults.default_best_before_days !== null)
		{
			GrocyBarcodeLookup.SetDueDays(defaults.default_best_before_days);
		}
	});
};

// Back to the page the lookup came from, with the product selected there
GrocyBarcodeLookup.ReturnTo = function (productName)
{
	window.location.href = U(Grocy.CurrentUrlRelative + "?flow=InplaceNewProductWithName&" + GrocyBarcodeLookup.State.returnFlow + "&product-name=" + encodeURIComponent(productName));
};

GrocyBarcodeLookup.Create = function ()
{
	var form = document.getElementById("barcode-lookup-form");
	form.classList.add("was-validated");

	if (!form.checkValidity())
	{
		return;
	}

	var result = GrocyBarcodeLookup.State.data.result;
	var name = $("#barcode-lookup-name").val().trim();
	var data = {
		"barcode": result.barcode,
		"name": name,
		"product_group_id": $("#barcode-lookup-group").val(),
		"location_id": $("#barcode-lookup-location").val(),
		"qu_id": $("#barcode-lookup-qu").val(),
		"package_size": result.package_size || "",
		"default_best_before_days": GrocyBarcodeLookup.DueDays(),
		"min_stock_amount": Math.max(0, parseFloat($("#barcode-lookup-min-stock").val()) || 0),
		"image_url": result.image_url && result.image_url !== "profile" ? result.image_url : "",
		"picture_profile": result.picture ? result.picture.profile : "",
		"picture_file": result.picture ? result.picture.file : "",
		"category_tags": result.category_tags || []
	};

	var buttons = GrocyBarcodeLookup.Dialog.find(".modal-footer .btn").prop("disabled", true);

	Grocy.Api.Post("barcodelookup/products", data,
		function ()
		{
			toastr.success(__t('Product "%s" created', GrocyBarcodeLookup.Escape(name)));
			GrocyBarcodeLookup.ReturnTo(name);
		},
		function (xhr)
		{
			buttons.prop("disabled", false);
			toastr.error(GrocyBarcodeLookup.ErrorText(xhr));
		}
	);
};

$(document).on("click", "#barcode-lookup-add-to-existing", function ()
{
	var result = GrocyBarcodeLookup.State.data.result;
	var button = $(this).prop("disabled", true);

	Grocy.Api.Post("objects/product_barcodes", { "product_id": result.existing_product.id, "barcode": result.barcode },
		function ()
		{
			GrocyBarcodeLookup.ReturnTo(result.existing_product.name);
		},
		function (xhr)
		{
			button.prop("disabled", false);
			toastr.error(GrocyBarcodeLookup.ErrorText(xhr));
		}
	);
});
