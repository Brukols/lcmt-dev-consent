const path = require("path");
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const { WebpackManifestPlugin } = require("webpack-manifest-plugin");

module.exports = (env, argv) => {
    const isProd = argv.mode === "production";
    return {
        entry: {
            banner: "./assets/src/banner.ts",
            youtube: "./assets/src/youtube.ts"
        },
        output: {
            path: path.resolve(__dirname, "assets/dist"),
            filename: isProd ? "[name].[contenthash:8].js" : "[name].js",
            clean: true
        },
        devtool: isProd ? false : "source-map",
        resolve: {
            extensions: [".ts", ".js"]
        },
        module: {
            rules: [
                {
                    test: /\.ts$/,
                    use: {
                        loader: "esbuild-loader",
                        options: { target: "es2018" }
                    },
                    exclude: /node_modules/
                },
                {
                    test: /\.scss$/,
                    use: [MiniCssExtractPlugin.loader, "css-loader", "sass-loader"]
                }
            ]
        },
        plugins: [
            new MiniCssExtractPlugin({
                filename: isProd ? "[name].[contenthash:8].css" : "[name].css"
            }),
            new WebpackManifestPlugin({
                publicPath: "",
                filter: (file) => /\.(js|css)$/.test(file.name)
            })
        ],
        optimization: {
            minimize: isProd
        }
    };
};
