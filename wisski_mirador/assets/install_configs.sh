#!/bin/bash

PJSONDIR="../../../../../libraries/mirador-integration/"
INDEXDIR="../../../../../libraries/mirador-integration/src/"
WEBPACKDIR="../../../../../libraries/mirador-integration/webpack/"

while true; do
    read -p "Do you want to overwrite the index.js, package.json, and webpack.config.js? (y/n): " yn
    case $yn in
        [Yy]* ) break;;
        [Nn]* ) echo "OK bye"; exit;;
        * ) echo "Please answer yes or no.";;
    esac
done

MESSAGE="Have you cloned the Mirador Integration library in the libraries directory of your web root and is it called 'mirador-integration'?"

if [ -d "$PJSONDIR" ]; then
  echo "Copying package.json file to ${PJSONDIR}"
  cp ./package.json $PJSONDIR
else
  echo "Error: ${PJSONDIR} not found. ${MESSAGE}"
  exit 1
fi

if [ -d "$INDEXDIR" ]; then
  echo "Copying index.js files in ${INDEXDIR}"
  cp ./index.js $INDEXDIR
else
  echo "Error: ${INDEXDIR} not found. ${MESSAGE}"
  exit 1
fi

if [ -d "$WEBPACKDIR" ]; then
  echo "Copying webpack.config.js files in ${WEBPACKDIR}"
  cp ./webpack.config.js $WEBPACKDIR
else
  echo "Error: ${WEBPACKDIR} not found. ${MESSAGE}"
  exit 1
fi

echo "Seems everything went well, now type 'npm i' and 'npm run webpack' at the libraries/mirador-integration directory to install the dependencies and build the library!"

exit 0