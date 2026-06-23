const Encore = require('@symfony/webpack-encore');

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

    .enableSassLoader()
    .configureBabelPresetEnv((config) => {
        config.useBuiltIns = 'usage';
        config.corejs = '3.36';
    })
    .enableSourceMaps(!Encore.isProduction())
;

module.exports = Encore.getWebpackConfig();
