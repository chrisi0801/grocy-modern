// Shorter, calmer forms (Grocy Modern).
//
// grocy shows every field of the product and purchase forms at once, in one
// long column - most of them rarely needed. This regroups the existing
// fields when the page loads: what is needed every time stays on top, the
// rest goes into sections that open on a tap and say in one line what is
// set inside. The Blade views stay untouched (merges with upstream grocy),
// fields keep their ids and names, so grocy's own scripts work as before.
//
// Also: errors only show once a field was edited or saving was tried, not
// as a red wall on an empty form.

var GrocyForms = {};

GrocyForms.Group = function (id)
{
	var field = $("#" + id);

	if (field.length === 0 || field.attr("type") === "hidden")
	{
		return $();
	}

	return field.closest(".form-group");
};

GrocyForms.LabelText = function (id)
{
	var label = $('label[for="' + id + '"]').first().clone();
	label.find("i, .small").remove();
	return label.text().trim();
};

GrocyForms.Number = function (id)
{
	var field = $("#" + id);
	return field.length ? (Number.parseFloat(String(field.val()).replace(",", ".")) || 0) : 0;
};

GrocyForms.Checked = function (id)
{
	return $("#" + id).is(":checked");
};

GrocyForms.SelectedText = function (id)
{
	var field = $("#" + id);
	return field.length && field.val() ? field.find("option:selected").text().trim() : "";
};

GrocyForms.State = function ()
{
	try
	{
		return JSON.parse(window.localStorage.getItem("grocy_form_sections") || "{}") || {};
	}
	catch (e)
	{
		return {};
	}
};

GrocyForms.Remember = function (form, key, open)
{
	try
	{
		var state = GrocyForms.State();
		state[form] = state[form] || {};
		state[form][key] = open;
		window.localStorage.setItem("grocy_form_sections", JSON.stringify(state));
	}
	catch (e)
	{
	}
};

// Fields side by side - two columns, also on a phone
GrocyForms.Row = function (anchor, groups)
{
	groups = groups.filter(function (group)
	{
		return group.length > 0;
	});

	if (groups.length < 2)
	{
		return;
	}

	var row = $('<div class="form-row gm-form-row"></div>').insertBefore(anchor);
	groups.forEach(function (group)
	{
		$('<div class="col-6"></div>').append(group).appendTo(row);
	});
};

// A section that opens on a tap; summary() says in one line what is set
GrocyForms.Section = function (form, options)
{
	var groups = options.groups.filter(function (group)
	{
		return group.length > 0;
	});

	if (groups.length === 0)
	{
		return null;
	}

	var formId = form.attr("id");
	var id = formId + "-section-" + options.key;
	var section = $('<div class="gm-section" data-section="' + options.key + '">'
		+ '<button type="button" class="gm-section-head" aria-expanded="false" aria-controls="' + id + '">'
		+ '<i class="fa-solid ' + options.icon + ' gm-section-icon"></i>'
		+ '<span class="gm-section-text"><span class="gm-section-title"></span><span class="gm-section-summary"></span></span>'
		+ '<i class="fa-solid fa-chevron-down gm-section-chevron"></i>'
		+ "</button>"
		+ '<div class="gm-section-body" id="' + id + '" hidden></div>'
		+ "</div>");

	section.find(".gm-section-title").text(options.title);
	section.insertBefore(options.before);

	var body = section.find(".gm-section-body");
	groups.forEach(function (group)
	{
		body.append(group);
	});

	var head = section.find(".gm-section-head");
	var update = function ()
	{
		var parts = (options.summary ? options.summary() : []).filter(Boolean);
		section.find(".gm-section-summary").text(parts.join(" · "));
	};

	section.data("open", function (open, remember)
	{
		body.prop("hidden", !open);
		head.attr("aria-expanded", open ? "true" : "false");
		section.toggleClass("gm-section-open", open);

		if (remember)
		{
			GrocyForms.Remember(formId, options.key, open);
		}
	});

	head.on("click", function ()
	{
		section.data("open")(body.prop("hidden"), true);
	});

	body.on("change input", update);
	update();
	// Values set by grocy's own scripts after loading (presets, product defaults)
	setTimeout(update, 500);
	form.on("gm-refresh", update);

	section.data("open")(!!(GrocyForms.State()[formId] || {})[options.key], false);
	return section;
};

// Errors only for fields that were edited, or after trying to save
GrocyForms.QuietValidation = function (form, saveSelector)
{
	form.addClass("gm-quiet-validation");

	form.on("input change", ":input", function (e)
	{
		// Only what the user did - grocy's own prefilling triggers change too
		if (e.originalEvent && e.originalEvent.isTrusted)
		{
			$(this).closest(".form-group").addClass("gm-touched");
		}
	});

	var mark = function (open)
	{
		form.find(".gm-section").each(function ()
		{
			var section = $(this);
			var invalid = section.find(":invalid").not("fieldset").length > 0;
			section.toggleClass("gm-section-invalid", invalid);

			if (invalid && open)
			{
				section.data("open")(true, false);
			}
		});
	};

	var tried = function ()
	{
		form.addClass("gm-tried");
		// A field grocy reports as missing must be visible to be focused
		mark(true);
	};

	// Once fixed, a section is no longer marked
	form.on("input change", function ()
	{
		if (form.hasClass("gm-tried"))
		{
			setTimeout(function ()
			{
				mark(false);
			}, 0);
		}
	});

	form[0].addEventListener("click", function (e)
	{
		if ($(e.target).closest(saveSelector).length)
		{
			tried();
		}
	}, true);

	form[0].addEventListener("keydown", function (e)
	{
		if (e.key === "Enter" && !$(e.target).is("textarea, .note-editable"))
		{
			tried();
		}
	}, true);
};

GrocyForms.ProductForm = function (form)
{
	var g = GrocyForms.Group;
	var top = g("name");

	// "Never overdue" next to the due days, instead of the hint "-1 means ..."
	var dueDays = $("#default_best_before_days");
	if (dueDays.length && dueDays.attr("type") !== "hidden")
	{
		var never = $('<div class="custom-control custom-checkbox gm-never-overdue">'
			+ '<input type="checkbox" class="custom-control-input" id="gm-default-due-never">'
			+ '<label class="custom-control-label" for="gm-default-due-never"></label></div>');
		never.find("label").text(__t("Never overdue"));
		g("default_best_before_days").append(never);

		var before = Math.max(0, GrocyForms.Number("default_best_before_days"));
		var apply = function (isNever)
		{
			dueDays.prop("readonly", isNever).toggleClass("gm-value-hidden", isNever);
			g("default_best_before_days").find(".input-group button, .input-group .btn").prop("disabled", isNever);
		};

		$("#gm-default-due-never").prop("checked", GrocyForms.Number("default_best_before_days") === -1).on("change", function ()
		{
			if (this.checked)
			{
				before = Math.max(0, GrocyForms.Number("default_best_before_days"));
				dueDays.val(-1);
			}
			else
			{
				dueDays.val(before);
			}

			apply(this.checked);
			dueDays.trigger("change").trigger("keyup");
		});
		apply(GrocyForms.Number("default_best_before_days") === -1);
	}

	// What is needed for every product, on top
	var group = g("product_group_id");
	var location = g("location_id");
	var stockUnit = g("qu_id_stock");
	var minStock = g("min_stock_amount");
	var due = g("default_best_before_days");
	var anchor = $('<div class="gm-anchor"></div>').insertAfter(top);

	group.insertBefore(anchor);
	location.insertBefore(anchor);
	stockUnit.insertBefore(anchor);
	minStock.insertBefore(anchor);
	due.insertBefore(anchor);
	GrocyForms.Row(location, [location, stockUnit]);
	GrocyForms.Row(minStock, [minStock, due]);

	var unitIfOther = function (id, text)
	{
		var unit = GrocyForms.SelectedText(id);
		return unit && unit !== GrocyForms.SelectedText("qu_id_stock") ? __t(text, unit) : "";
	};
	var days = function (id, text)
	{
		var value = GrocyForms.Number(id);
		return value === -1 ? __t(text, __t("never overdue")) : (value > 0 ? __t(text, __t("%s days", value)) : "");
	};
	var label = function (id)
	{
		return GrocyForms.Checked(id) ? GrocyForms.LabelText(id) : "";
	};

	GrocyForms.Section(form, {
		key: "due",
		title: __t("Keeping"),
		icon: "fa-hourglass-half",
		before: anchor,
		groups: [g("due-type-bestbefore"), g("default_best_before_days_after_open"), g("default_best_before_days_after_freezing"), g("default_best_before_days_after_thawing"), g("should_not_be_frozen")],
		summary: function ()
		{
			return [
				$("#due-type-expiration").is(":checked") ? GrocyForms.LabelText("due-type-expiration") : "",
				days("default_best_before_days_after_open", "opened: %s"),
				days("default_best_before_days_after_freezing", "frozen: %s"),
				days("default_best_before_days_after_thawing", "thawed: %s"),
				label("should_not_be_frozen")
			];
		}
	});

	GrocyForms.Section(form, {
		key: "consume",
		title: __t("Consuming & opening"),
		icon: "fa-utensils",
		before: anchor,
		groups: [g("default_consume_location_id"), g("qu_id_consume"), g("quick_consume_amount"), g("quick_open_amount"), g("treat_opened_as_out_of_stock"), g("disable_open"), g("enable_tare_weight_handling"), g("tare_weight"), g("not_check_stock_fulfillment_for_recipes")],
		summary: function ()
		{
			var location = GrocyForms.SelectedText("default_consume_location_id");
			return [
				location ? __t("from %s", location) : "",
				unitIfOther("qu_id_consume", "consumed in %s"),
				label("move_on_open"),
				label("disable_open"),
				label("enable_tare_weight_handling")
			];
		}
	});

	GrocyForms.Section(form, {
		key: "purchase",
		title: __t("Purchasing"),
		icon: "fa-cart-shopping",
		before: anchor,
		groups: [g("shopping_location_id"), g("qu_id_purchase"), g("qu_id_price"), g("default-purchase-price-type-unspecified")],
		summary: function ()
		{
			var store = $("#shopping_location_id option:selected").text().trim();
			var priceType = $('input[name="default_purchase_price_type"]:checked');
			return [
				store,
				unitIfOther("qu_id_purchase", "bought in %s"),
				unitIfOther("qu_id_price", "prices per %s"),
				priceType.length && priceType.val() !== "1" ? GrocyForms.LabelText(priceType.attr("id")) : ""
			];
		}
	});

	GrocyForms.Section(form, {
		key: "description",
		title: __t("Description"),
		icon: "fa-align-left",
		before: anchor,
		groups: [g("description")],
		summary: function ()
		{
			var text = $("<div></div>").html($("#description").val() || "").text().trim();
			return [text.length > 60 ? text.substring(0, 60) + "…" : text];
		}
	});

	GrocyForms.Section(form, {
		key: "more",
		title: __t("More settings"),
		icon: "fa-sliders",
		before: anchor,
		groups: [g("active"), form.find("#product_id").closest(".form-group"), g("cumulate_min_stock_amount_of_sub_products"), g("calories"), g("hide_on_stock_overview"), g("no_own_stock"), g("default_stock_label_type"), g("auto_reprint_stock_label"), form.find("#userfields-form")],
		summary: function ()
		{
			var parent = $("#product_id").val() ? $("#product_id option:selected").text().trim() : "";
			return [
				GrocyForms.Checked("active") ? "" : __t("Not active"),
				parent ? __t("part of %s", parent) : "",
				label("hide_on_stock_overview"),
				label("no_own_stock")
			];
		}
	});

	GrocyForms.QuietValidation(form, ".save-product-button");
};

GrocyForms.PurchaseForm = function (form)
{
	var g = GrocyForms.Group;

	// Price, and whether it is per unit or for all, in one place
	var priceTypes = form.find('input[name="price-type"]').closest(".custom-control");
	if (priceTypes.length)
	{
		var toggle = $('<div class="gm-segmented" role="radiogroup"></div>');
		priceTypes.each(function ()
		{
			$(this).removeClass("custom-control custom-radio custom-control-inline mt-n2 mb-3").addClass("gm-segment");
			$(this).find("input").removeClass("custom-control-input");
			$(this).find("label").removeClass("custom-control-label");
			toggle.append(this);
		});
		g("price").removeClass("mb-1").find("label[for=price]").first().after(toggle);
	}

	// "means 2.25 per pack" appears when leaving the price field - in a line
	// of its own that is always there, so the OK button doesn't jump away
	// right under the finger that taps it
	$("#price-hint").removeClass("float-right mt-1").addClass("gm-price-hint").insertAfter(g("price").find(".input-group").first());

	var save = $("#save-purchase-button");

	GrocyForms.Section(form, {
		key: "more",
		title: __t("Store, location, note"),
		icon: "fa-ellipsis",
		before: save,
		groups: [g("shopping_location_id"), g("location_id"), g("stock_label_type"), g("note"), form.find("#userfields-form")],
		summary: function ()
		{
			var note = ($("#note").val() || "").trim();
			return [
				$("#location_id option:selected").text().trim(),
				$("#shopping_location_id option:selected").text().trim(),
				$("#stock_label_type").val() && $("#stock_label_type").val() !== "0" ? $("#stock_label_type option:selected").text().trim() : "",
				note ? "„" + (note.length > 30 ? note.substring(0, 30) + "…" : note) + "“" : ""
			];
		}
	});

	// The product's default location is set after choosing it
	form.on("change", "#product_id", function ()
	{
		setTimeout(function ()
		{
			form.trigger("gm-refresh");
		}, 800);
	});

	GrocyForms.QuietValidation(form, "#save-purchase-button");
};

// An empty product card is just a column of labels - it shows up once a
// product is chosen (purchase, consume, inventory, transfer)
var productCard = $(".productcard").not(".modal .productcard");
if (productCard.length && $("#product_id").length)
{
	var productCardColumn = productCard.closest('[class*="col-"]');
	var toggleProductCard = function ()
	{
		productCardColumn.toggleClass("d-none", !$("#product_id").val());
	};

	$(document).on("change", "#product_id", toggleProductCard);
	toggleProductCard();
}

// Amount and unit side by side wherever grocy asks for both
$("#display_amount").closest(".form-group.row").find("> .col > .row").addClass("gm-amount-row");

if ($("#product-form").length)
{
	GrocyForms.ProductForm($("#product-form"));
}

if ($("#purchase-form").length)
{
	GrocyForms.PurchaseForm($("#purchase-form"));
}
