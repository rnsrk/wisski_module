# Mirador Block

## Mirador implementation in a block

Include the block within the normal block structure.

There are two parameters for the block configuration:

**IIIF Field Number** : Id of the field that stores and displays the url of a IIIF manifest. The Mirador viewer will only be visible when this field has information. To find the ID of a field go to Structue -> Wisski Entities and Bundles. Select one of the bundles where the field is included and then choose the tab "Manage Fields". The required ID for each field is listed under "System Name".

**Height**: The height of the Mirador Block in pixels.

## Installation

### Compile Mirador 3

You have to install Mirador 3 for this package to work. To compile the Mirador 3 javascript file, you will need to have git and [npm](https://docs.npmjs.com/downloading-and-installing-node-js-and-npm) installed. Then you can run the following commands to clone the mirador repository: 

```
git clone https://github.com/ProjectMirador/mirador.git mirador
cd mirador
npm install
npm run build
```

After that you have the following files in the `dist` subdirectory:

- mirador.min.js  
- LICENSE.txt  
- mirador.min.js.map

### Copying Mirador into your Drupal Libraries

You need to copy the `dist` folder into your Drupal at /libraries/mirador (in the drupal root directory)

