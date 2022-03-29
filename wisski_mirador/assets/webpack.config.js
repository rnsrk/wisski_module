const path = require('path');

const webpack = require('webpack');

module.exports = {
  entry: './src/index.js',
//  debug: true,
  devtool: 'source-map',
  output: {
    filename: 'main.js',
    path: path.resolve(__dirname, 'dist'),
    publicPath: './dist/',
  },
  plugins: [
    new webpack.optimize.LimitChunkCountPlugin({
        maxChunks: 1, // disable creating additional chunks
    })
],
  
};
