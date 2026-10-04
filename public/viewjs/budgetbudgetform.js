$("#save-budget-budget-button").on("click", function (e)
{
	e.preventDefault();

	if (!Grocy.FrontendHelpers.ValidateForm("budget-budget-form", true))
	{
		return;
	}

	var data = {
		"name": $("#name").val(),
		"owner_user_id": $("#owner_user_id").val()
	};

	var done = function (result)
	{
		var budgetId = BudgetForm.Mode === "create" ? result.created_object_id : BudgetForm.BudgetId;
		GrocyBudget.Done(U("/budget/settings?budget=" + budgetId));
	};

	var failed = function (xhr)
	{
		Grocy.FrontendHelpers.EndUiBusy("budget-budget-form");
		GrocyBudget.ShowError(xhr);
	};

	Grocy.FrontendHelpers.BeginUiBusy("budget-budget-form");

	if (BudgetForm.Mode === "create")
	{
		Grocy.Api.Post("budget/budgets", data, done, failed);
	}
	else
	{
		Grocy.Api.Put("budget/budgets/" + BudgetForm.BudgetId, data, done, failed);
	}
});

$("#budget-budget-form input").on("keydown", function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();
		$("#save-budget-budget-button").click();
	}
});

setTimeout(function ()
{
	$("#name").focus();
}, Grocy.FormFocusDelay);
