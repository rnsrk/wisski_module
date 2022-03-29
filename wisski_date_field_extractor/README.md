## About

This Drupal/WissKI module developed by the University Library Heidelberg provides field types for the automatic extraction of year information from potentially incomplete date input by a user. In doing so, the year information can be used for calculations and searches. 

There are currently two field types provided by the module, 

 1. a type for a date input roughly following the specifications in EDTF, Extended Date/Time Format, see https://www.loc.gov/standards/datetime/, and
 2. a type for a rather "verbal" input, as defined in MIDAS (link?).

Optionally, the user input can be validated. If desired, the input will only be saved if it corresponds to one of the patterns defined in the module.


## Examples

 * Input "1912" will set both the begin year and the end year to "1912". 
 * Input "1914-07" will set both the begin year and the end year to "1914".
 * Input "1880-07/1882-04" will set the begin year to "1880" and the end year to "1882".

 In the "verbal date" case, examples are

  * "18. Jhd" ("18th century")
  * "Anfang 18. Jhd" ("beginning of 18th century")
  * "bis 1789" ("until 1789")

## Installation

Tic `Extend -> WissKI Date Field`, then click `Save`

## Modeling in Pathbuilder

Go to `Configuration -> WISSKI -> Pathbuilders -> <main pathbuilder>`. First, create the field for the date input, e. g. "Werk -> Erstelldatum" ("work -> date of creation" as in `<Bundle> -> <Date field>`):

```
ecrm:E22_Man-Made_Object -> ecrm:P12i_was_present_at -> ecrm:E12_Production -> ecrm:P4_has_time-span -> ecrm:E52_Time-Span
```

As a `datatype property` choose `P3_has_note`. As the `Type of the field that should be generated.` choose `WissKI Date Field`.

Then, create two more fields. These will be automatically filled by the begin year and end year extracted from the user input. In the example, we use the same path, but different `datatype properties`: `P79_beginning_is_qualified_by` is chosen for the begin year extracted, and `P80_ending_is_qualified_by` is chosen for the end year extracted. The `Type of field that should be generated` should be `Number (integer)`.

Click `Save and generate bundles and fields`. Now you can use the three new fields.

## Fill field IDs into the module's settings form

You can now add the field IDs to the module's settings form. Go to `Structure -> WissKI Entities and Bundles -> <Bundle> -> Manage form display -> <Date field>` (in the example: `Structure -> WissKI Entities and Bundles -> Werk -> Manage form display -> Erstelldatum`). The field IDs can be found under `Structure -> WissKI Entities and Bundles -> <Bundle> -> Manage fields -> <Field>`). Click `Save`. That's it.

## Try out

In your WissKI instance, go to an individual of `<Bundle>` (in the example: "Werk"), click on `Edit`, and type some date into the `<Date field>` (like for example "1943-05" or "1952/1954"). After clicking on `Save`, the input typed into the field can be seen in the view, and furthermore the begin an end year generated from the module are inserted automatically into the other two fields.

Since the data type of the beginning and end fields is `Integer`, these fields can be used in searches in connection with the logical operators larger than (`>`) or less than(`<`).
