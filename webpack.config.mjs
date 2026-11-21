import Encore from '@symfony/webpack-encore';

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    // Built assets are served by AssetController from this directory.
    .setOutputPath('public/build/')
    .setPublicPath('/discussion/asset')
    .setManifestKeyPrefix('')

    // Single entry: discussion.js also imports discussion.scss -> discussion.css
    .addEntry('discussion', './assets/discussion.js')

    // Stable filenames (no hash) so AssetController can serve discussion.{js,css}.
    .enableVersioning(false)
    .disableSingleRuntimeChunk()
    .cleanupOutputBeforeBuild()

    // Encore >= 7.2.1 asks Sass for expanded output and leaves minification to a CSS minimizer
    // that is off unless configured; Sass's own compressed style keeps discussion.css minified.
    .enableSassLoader((options) => {
        options.sassOptions.style = Encore.isProduction() ? 'compressed' : 'expanded';
    })
    // Babel 8 dropped preset-env's useBuiltIns/corejs; the plugin injects the polyfills instead.
    .configureBabel((config) => {
        config.plugins.push(['babel-plugin-polyfill-corejs3', { method: 'usage-global', version: '3.36' }]);
    })
    .enableSourceMaps(!Encore.isProduction())
;

export default await Encore.getWebpackConfig();
