function BudgetCategoryIsIncome()
{
	return $("input[name='type']:checked").val() === "income";
}

// Plan and carryover only mean something for expenses
function BudgetRefreshCategoryForm()
{
	$(".budget-expense-only").toggleClass("d-none", BudgetCategoryIsIncome());
}

$("input[name='type']").on("change", BudgetRefreshCategoryForm);

$("#plan").on("input", function ()
{
	var text = $(this).val().trim();
	var plan = GrocyBudget.ParseAmount(text);
	this.setCustomValidity(text === "" || (plan !== null && plan >= 0) ? "" : __t("Please enter an amount"));
});

$("#save-budget-category-button").on("click", function (e)
{
	e.preventDefault();

	$("#plan").trigger("input");
	if (!Grocy.FrontendHelpers.ValidateForm("budget-category-form", true))
	{
		return;
	}

	var isIncome = BudgetCategoryIsIncome();
	var data = {
		"name": $("#name").val(),
		"is_income": isIncome,
		"carryover": !isIncome && $("#carryover").is(":checked")
	};

	if (BudgetForm.SortOrder !== null)
	{
		data.sort_order = BudgetForm.SortOrder;
	}

	var done = function ()
	{
		GrocyBudget.Done(U("/budget/settings?budget=" + BudgetForm.BudgetId));
	};

	var failed = function (xhr)
	{
		Grocy.FrontendHelpers.EndUiBusy("budget-category-form");
		GrocyBudget.ShowError(xhr);
	};

	Grocy.FrontendHelpers.BeginUiBusy("budget-category-form");

	if (BudgetForm.Mode === "create")
	{
		Grocy.Api.Post("budget/budgets/" + BudgetForm.BudgetId + "/categories", data,
			function (result)
			{
				var plan = GrocyBudget.ParseAmount($("#plan").val());

				if (isIncome || plan === null || plan === 0)
				{
					done();
					return;
				}

				Grocy.Api.Put("budget/categories/" + result.created_object_id + "/plan", { "month": BudgetForm.CurrentMonth, "amount": plan }, done, failed);
			},
			failed
		);
	}
	else
	{
		Grocy.Api.Put("budget/categories/" + BudgetForm.CategoryId, data, done, failed);
	}
});

$("#budget-category-form input").on("keydown", function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();
		$("#save-budget-category-button").click();
	}
});

BudgetRefreshCategoryForm();

setTimeout(function ()
{
	$("#name").focus();
}, Grocy.FormFocusDelay);
