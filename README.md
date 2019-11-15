= SIMPLE DMARC AGGREGATOR =

The Simple DMARC Aggregator (SDA) is a collection of PHP scripts which process 
RFC7489 ( https://tools.ietf.org/html/rfc7489 ) DMARC feedback messages. It is
designed to be installed in a subfolder of `public_html` on a shared hosting 
service, for which you have access to a CPanel installation which supports email
filtering - though it could probably be repurposed for other tasks...

There are three components to the collection:

== (1) demime.php ==

This script reads an email (MIME) message from standard input (STDIN), as piped
by the CPanel mail filter system. It parses the message and looks for indicators
that it is a DMARC feedback message. If it is, then the DMARC XML file (compressed
as `.xml.gz` or `.zip` is saved off in the `tmp` folder. 

If debugging is enabled (`LOG_INVALID_EML` is `TRUE`) then non-DMARC messages  
are recorded into the `eml` folder, saved as a `.eml` (MIME) format message. 

The script is very careful not to throw exceptions because this will cause emails
to bounce, potentially causing the feedback address to be blacklisted. As this
is bad, there are lots of `try{}catch{}` blocks. If you still find a `php_errorlog`
PLEASE file a bug report!

When configuring Cpanel, create a message filter ("Account Filter") which matches
emails sent to the `rua=mailto:abuse@${YOURDOMAIN}` entry in your DMARC record.
Set the filter to value to

`     /usr/local/bin/php $home/public_html/dmarc-reports/demime.php`.

Which will allow the script to process inbound emails. Once you are convinced it
is working, you can add another step to discard matching messages and stop 
processing rules. 

<< img >>


== (2) demarker.php ==

This script provides GET-based querying of the temporary files stored by demime.
Assuming this code is deployed to 'public_html/dmarc-reports', you can ask for 
(for example) a summary in JSON format of reports for the last month using:

`yourdomain.com/dmarc-reports/demarker.php?fmt=json&t=-1%20month` 

The parameters of this file 

- `fmt=` - one of `json`, `csv`, or `json,csv` (useful for debugging) 
determines the output format, which is currently JSON or CSV
- `errors_only=` - either `false` (returns everything, might take a while)
or `true` (or omitted or anything) skips records without errors
- `t=` - a PHP time spec, by default `-1 month`. Supports absolute and relative,
see https://www.php.net/manual/en/datetime.formats.relative.php for supported 
formats. 

== (3) dmarccron.php ==

**TODO** 

This script takes the zip/gz files in the `tmp` folder and performs some 
preliminary processing steps:

*) extract raw XML data
*) check the date range, move file to correct `data/YYYYMM/` folder
*) for each folder that is not this `YYYYMM`, 
