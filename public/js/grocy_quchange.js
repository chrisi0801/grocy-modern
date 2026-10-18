// Counting a product's stock in another unit after it was once in stock
// (Grocy Modern). grocy allows that only when a unit conversion from the old
// to the new unit exists, and then converts stock, journal, shopping list
// and recipes by itself (trigger cascade_change_qu_id_stock). This asks one
// question - "1 Packung = how many Rollen?" -, creates that conversion for
// the product and saves it with the new unit.

$("#qu-stock-change-button").on("click", function ()
{
	var button = $(this);
	var units = button.data("units");
	var productId = button.data("product-id");
	var current = units.find(function (unit)
	{
		return unit.id == button.data("current-qu");
	});
	var offered = $("#qu_id_stock option").map(function ()
	{
		return this.value;
	}).get();
	var others = units.filter(function (unit)
	{
		return offered.indexOf(String(unit.id)) === -1;
	});
	var escape = function (text)
	{
		return $("<div></div>").text(text || "").html();
	};
	var plural = function (unit)
	{
		return unit.name_plural || unit.name;
	};

	var options = others.map(function (unit)
	{
		return '<option value="' + unit.id + '">' + escape(unit.name) + "</option>";
	}).join("");

	var dialog = bootbox.dialog({
		title: __t("Count stock in another unit"),
		message: '<p>' + __t('Since its first purchase, the stock of this product is counted in "%s". For another unit grocy needs to know how the two relate - everything in stock, on shopping lists and in recipes is converted then.', escape(current.name)) + "</p>"
			+ '<form id="qu-change-form" novalidate>'
			+ '<div class="form-group"><label for="qu-change-to">' + __t("New unit") + "</label>"
			+ '<select class="custom-control custom-select" id="qu-change-to">' + options + "</select></div>"
			+ '<div class="form-group"><label for="qu-change-factor">' + __t("Conversion") + "</label>"
			+ '<div class="input-group"><div class="input-group-prepend"><span class="input-group-text">1 ' + escape(current.name) + " =</span></div>"
			+ '<input type="number" class="form-control" id="qu-change-factor" inputmode="decimal" min="0.0001" step="any" required>'
			+ '<div class="input-group-append"><span class="input-group-text" id="qu-change-to-name"></span></div>'
			+ '<div class="invalid-feedback">' + __t("Please enter a number greater than 0") + "</div></div></div>"
			+ '<p class="small text-muted mb-0" id="qu-change-preview"></p>'
			+ "</form>",
		onEscape: true,
		backdrop: true,
		closeButton: true,
		buttons: {
			cancel: {
				label: __t("Cancel"),
				className: "btn-secondary"
			},
			ok: {
				label: '<i class="fa-solid fa-check"></i> ' + __t("Change and save"),
				className: "btn-success",
				callback: function ()
				{
					var form = document.getElementById("qu-change-form");
					form.classList.add("was-validated");

					if (!form.checkValidity())
					{
						return false;
					}

					var target = others.find(function (unit)
					{
						return unit.id == $("#qu-change-to").val();
					});
					var buttons = dialog.find(".modal-footer .btn").prop("disabled", true);

					Grocy.Api.Post("objects/quantity_unit_conversions", {
						"from_qu_id": current.id,
						"to_qu_id": target.id,
						"factor": parseFloat($("#qu-change-factor").val()),
						"product_id": productId
					},
						function ()
						{
							// The new unit is selectable now - in the same place in all four
							// lists, grocy presets the others by position
							$("#qu_id_stock, #qu_id_purchase, #qu_id_consume, #qu_id_price").each(function ()
							{
								if ($(this).find('option[value="' + target.id + '"]').length === 0)
								{
									$(this).append($("<option></option>").val(target.id).text(target.name).attr("data-plural-form", target.name_plural));
								}
							});

							$("#qu_id_stock").val(target.id).trigger("change");
							dialog.modal("hide");
							$("#save-product-button").click();
						},
						function (xhr)
						{
							buttons.prop("disabled", false);
							Grocy.FrontendHelpers.ShowGenericError("Error while saving", xhr.response);
						}
					);

					return false;
				}
			}
		}
	});

	var stockAmount = null;
	var preview = function ()
	{
		var target = others.find(function (unit)
		{
			return unit.id == $("#qu-change-to").val();
		});
		var factor = parseFloat($("#qu-change-factor").val());

		$("#qu-change-to-name").text(target ? plural(target) : "");

		if (stockAmount === null || !target || !(factor > 0))
		{
			$("#qu-change-preview").text("");
			return;
		}

		var number = function (value)
		{
			return value.toLocaleString(undefined, { maximumFractionDigits: 4 });
		};

		$("#qu-change-preview").text(__t("In stock now: %1$s - afterwards: %2$s",
			number(stockAmount) + " " + (stockAmount == 1 ? current.name : plural(current)),
			number(stockAmount * factor) + " " + (stockAmount * factor == 1 ? target.name : plural(target))));
	};

	dialog.on("shown.bs.modal", function ()
	{
		$("#qu-change-factor").focus();
	});
	dialog.on("input change", "#qu-change-to, #qu-change-factor", preview);
	preview();

	Grocy.Api.Get("stock/products/" + productId,
		function (details)
		{
			stockAmount = parseFloat(details.stock_amount) || 0;
			preview();
		},
		function ()
		{
		}
	);
});
