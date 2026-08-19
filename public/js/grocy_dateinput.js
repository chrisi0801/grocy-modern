// Typing a date into the due date / purchased date fields.
//
// Grocy knows an eight digit shorthand, but only as YYYYMMDD. Everywhere that
// writes dates the German way that is the wrong way round, so DDMMYYYY is
// accepted as well. The year is what tells the two apart: it is the only four
// digit group that can plausibly be one, so 15052027 can only mean the 15th of
// May 2027, and 20270515 can only mean the same day written the other way.

var GrocyDateInput = {
	// Anything outside this is a day, a month, or a typo - never a year
	MinYear: 1900,
	MaxYear: 2999,
	// How long the four digit shorthand waits for more digits, see Defer()
	DeferDelay: 600
};

GrocyDateInput.IsPlausibleDate = function (isoDate)
{
	var year = Number.parseInt(isoDate.substring(0, 4), 10);

	if (year < GrocyDateInput.MinYear || year > GrocyDateInput.MaxYear)
	{
		return false;
	}

	return moment(isoDate, "YYYY-MM-DD", true).isValid();
};

// Returns the date as YYYY-MM-DD, or null when the digits are neither reading
GrocyDateInput.ResolveDigits = function (value)
{
	if (!/^\d{8}$/.test(value))
	{
		return null;
	}

	var yearFirst = value.substring(0, 4) + "-" + value.substring(4, 6) + "-" + value.substring(6, 8);
	var yearLast = value.substring(4, 8) + "-" + value.substring(2, 4) + "-" + value.substring(0, 2);

	// YYYYMMDD keeps precedence - it is what the shorthand always meant
	if (GrocyDateInput.IsPlausibleDate(yearFirst))
	{
		return yearFirst;
	}

	if (GrocyDateInput.IsPlausibleDate(yearLast))
	{
		return yearLast;
	}

	return null;
};

// The four digit MMDD shorthand is a complete date on its own, so it used to
// fire in the middle of an eight digit one - after "0105" of "01052027" it
// rewrote the field and jumped to the next input, and the remaining digits
// landed there. It now waits for more digits, and is flushed as soon as the
// field is left or Enter is pressed.
GrocyDateInput.Pending = null;

GrocyDateInput.Defer = function (inputElement, value, apply)
{
	GrocyDateInput.Cancel();

	GrocyDateInput.Pending = {
		input: inputElement,
		value: value,
		apply: apply,
		timer: window.setTimeout(function ()
		{
			GrocyDateInput.Flush();
		}, GrocyDateInput.DeferDelay)
	};
};

GrocyDateInput.Cancel = function ()
{
	if (GrocyDateInput.Pending)
	{
		window.clearTimeout(GrocyDateInput.Pending.timer);
		GrocyDateInput.Pending = null;
	}
};

// `moveFocus` is false when the field was simply left - the shorthand still
// resolves, but the focus stays wherever the user just put it
GrocyDateInput.Flush = function (moveFocus)
{
	var pending = GrocyDateInput.Pending;
	GrocyDateInput.Cancel();

	// Whatever the user did in the meantime wins
	if (pending && pending.input.val() === pending.value)
	{
		pending.apply(moveFocus !== false);
	}
};
