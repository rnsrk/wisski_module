# WissKI DOI Module
Digital Object Identifiers (DOI) are  unique, persistent identifying numbers for a document published online. This module offers the possibility to receive DOIs for single WissKI entities.
## Implementation
At this point, only [DataCite](https://datacite.org/) is supported. You have to [request a Repository account and a prefix](https://support.datacite.org/docs/getting-started) to use this service.
## Installation
Just install WissKI DOI on the _Extend_ page and enter your credentials at _Manage_ _Configuration_ _WissKI DOI Settings_ (WISSKI).
## Usage
As soon as you installed the module there is a new tab called _DOI_ in the entity menu. You can choose between to options.
You need to set the right permissions, if none administrator roles should be allowed to request DOIs.
### Get DOI for static state
This saves the current revision of the entity and request a DOI for this revision. No changes can then be made to it and the DOI always refers to this state of the record.
### Get DOI for current state
This requests a DOI for the current revision of the dataset. If a new revision is created, the DOI automatically points to it. The content of the dataset can be changed at any time.
### Edit and delete
You can edit the metadata of the DOI. If you are in Draft or Registered mode, WissKI fills the fields with the current local data; if you are in findable mode, WissKI receives the field data from the online repository.
## States
DOIs have three [states](https://support.datacite.org/docs/doi-states): Draft, registered and findable. Only drafts can be deleted, and registered and findable states can not reduce back to draft. Be careful here.
## Uninstall
Beware: If you remove the module, the Drupal database table "wisski_doi" is also removed, so you do not see, which entities have already a DOI. Simple do not uninstall this module or backup the database table "wisski_doi" if you like to preserve a concordance
