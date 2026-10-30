plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// The release signing key, private (signing.env and the keystore are export-ignored): see android/README.md.
val signing: Map<String, String> = rootProject.file("signing.env").takeIf { it.exists() }
    ?.readLines()
    ?.filter { '=' in it && !it.trimStart().startsWith("#") }
    ?.associate { it.substringBefore('=').trim() to it.substringAfter('=').trim() }
    ?: emptyMap()

android {
    namespace = "io.github.nicolopozzato.firefunds"
    compileSdk = 35
    // The ones in the build image (android/build.sh).
    buildToolsVersion = "35.0.0"

    defaultConfig {
        applicationId = "io.github.nicolopozzato.firefunds"
        minSdk = 26
        targetSdk = 35
        versionCode = 3
        versionName = "1.1.1"
    }

    signingConfigs {
        if (signing.isNotEmpty()) {
            create("release") {
                storeFile = rootProject.file(signing.getValue("STORE_FILE"))
                storePassword = signing.getValue("STORE_PASSWORD")
                keyAlias = signing.getValue("KEY_ALIAS")
                keyPassword = signing.getValue("KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"))
            signingConfig = signingConfigs.findByName("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}
