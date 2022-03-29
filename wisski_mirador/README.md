Installation guide:

You have to install Mirador 3 Integration for this package to work. It should typically be installed 
at libraries/mirador-integration in the drupal root directory. For this you can take (10.01.2022)
the following steps:

1. Change to your libraries directory

2. Clone the Mirador Integration library:
~~~bash
git clone git@github.com:ProjectMirador/mirador-integration.git
~~~

3. Change to the modules/contrib/wisski/wisski_mirador/assets directory

4. Copy the configuration files with the install script:
~~~bash
./install_configs.sh
~~~

5. Change back into the libraries/mirador-integration directory and install the dependencies and build the library.
~~~
npm i
npm run webpack
~~~


After that you have the following files in the dist subdirectory:
mirador.min.js  mirador.min.js.LICENSE.txt  mirador.min.js.map



