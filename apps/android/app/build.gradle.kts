plugins {
    id("com.android.application")
}

android {
    namespace = "br.com.maurinsoft.salareuniao"
    compileSdk = 37

    defaultConfig {
        applicationId = "br.com.maurinsoft.salareuniao"
        minSdk = 26
        targetSdk = 37
        versionCode = 1
        versionName = "1.0.0"

        manifestPlaceholders["appHost"] = "meet.seu-dominio.example"
        buildConfigField("String", "APP_HOST", "\"meet.seu-dominio.example\"")
    }

    buildFeatures {
        viewBinding = true
        buildConfig = true
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.17.0")
    implementation("androidx.appcompat:appcompat:1.8.0")
    implementation("com.google.android.material:material:1.13.0")
    implementation("androidx.work:work-runtime:2.11.2")
}
