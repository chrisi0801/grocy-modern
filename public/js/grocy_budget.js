// Household budget (Grocy Modern) - what the budget pages share.

var GrocyBudget = {
	// The UI language, so amounts read like the rest of the page
	Locale: document.documentElement.lang || undefined
};

GrocyBudget.Format = function (amount)
{
	return Number(amount || 0).toLocaleString(GrocyBudget.Locale, {
		style: "currency",
		currency: Grocy.Currency,
		minimumFractionDigits: 2,
		maximumFractionDigits: 2
	});
};

// What people type: "12,50", "12.50", "1.234,56", "-3" - returns a number,
// or null when it is none. Mirrors BudgetService::ToCents.
GrocyBudget.ParseAmount = function (text)
{
	if (typeof text === "number")
	{
		return Number.isFinite(text) ? text : null;
	}

	text = String(text || "").replace(/[^0-9,.\-]/g, "");

	if (text === "" || text === "-")
	{
		return null;
	}

	var lastComma = text.lastIndexOf(",");
	var lastDot = text.lastIndexOf(".");

	if (lastComma !== -1 && lastDot !== -1)
	{
		var decimal = lastComma > lastDot ? "," : ".";
		var thousands = decimal === "," ? "." : ",";
		text = text.split(thousands).join("").replace(decimal, ".");
	}
	else if (lastComma !== -1)
	{
		text = text.replace(",", ".");
	}

	var value = Number(text);
	return Number.isFinite(value) ? Math.round(value * 100) / 100 : null;
};

// Validation messages of the budget API are meant for people ("only the
// owner can change a personal budget"), so they are shown as they are
GrocyBudget.ShowError = function (xhr, fallback)
{
	var response = xhr && xhr.response;

	if (typeof response === "string")
	{
		try
		{
			response = JSON.parse(response);
		}
		catch (e)
		{
			response = null;
		}
	}

	if (response && response.error_message)
	{
		toastr.error(response.error_message);
	}
	else
	{
		Grocy.FrontendHelpers.ShowGenericError(fallback || "Error while saving, probably this item already exists", xhr && xhr.response);
	}
};

// After saving: back to the page behind the dialog, or on to the given page
GrocyBudget.Done = function (url)
{
	if (GetUriParam("embedded") !== undefined)
	{
		window.parent.postMessage(WindowMessageBag("Reload"), Grocy.BaseUrl);
	}
	else
	{
		window.location.href = url;
	}
};

GrocyBudget.ConfirmDelete = function (question, apiPath, url)
{
	bootbox.confirm({
		message: question,
		closeButton: false,
		buttons: {
			confirm: { label: __t("Yes"), className: "btn-danger" },
			cancel: { label: __t("No"), className: "btn-secondary" }
		},
		callback: function (result)
		{
			if (result !== true)
			{
				return;
			}

			Grocy.Api.Delete(apiPath, {},
				function ()
				{
					GrocyBudget.Done(url);
				},
				function (xhr)
				{
					GrocyBudget.ShowError(xhr, "Error while deleting, please retry");
				}
			);
		}
	});
};

// Fills a <select> with the categories of one budget. With onlyIncome set
// (true / false) the other kind is left out - or, given otherLabel, offered
// below in a group of its own. Keeps the current choice when it still fits.
GrocyBudget.FillCategories = function (select, categories, onlyIncome, selectedId, emptyLabel, otherLabel)
{
	select = $(select);
	var keep = selectedId !== undefined ? String(selectedId || "") : select.val();
	var filtered = onlyIncome !== null && onlyIncome !== undefined;
	var others = [];

	select.empty();
	select.append($("<option></option>").attr("value", "").text(emptyLabel || __t("Without category")));

	(categories || []).forEach(function (category)
	{
		var option = $("<option></option>").attr("value", category.id).text(category.name);

		if (filtered && Boolean(Number(category.is_income)) !== onlyIncome)
		{
			others.push(option);
			return;
		}

		select.append(option);
	});

	if (otherLabel && others.length > 0)
	{
		select.append($("<optgroup></optgroup>").attr("label", otherLabel).append(others));
	}

	if (keep && select.find('option[value="' + keep + '"]').length > 0)
	{
		select.val(keep);
	}
	else
	{
		select.val("");
	}
};

GrocyBudget.Today = function ()
{
	return moment().format("YYYY-MM-DD");
};
