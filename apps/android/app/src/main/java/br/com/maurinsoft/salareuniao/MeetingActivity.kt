package br.com.maurinsoft.salareuniao

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.view.ViewGroup
import android.webkit.PermissionRequest
import android.webkit.WebChromeClient
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import br.com.maurinsoft.salareuniao.databinding.ActivityMeetingBinding

class MeetingActivity : AppCompatActivity() {
    companion object {
        const val EXTRA_URL = "url"
        private const val MEDIA_PERMISSION_CODE = 4102
    }

    private lateinit var binding: ActivityMeetingBinding
    private lateinit var session: SessionStore
    private var pendingWebPermission: PermissionRequest? = null
    private var customView: View? = null
    private var customViewCallback: WebChromeClient.CustomViewCallback? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMeetingBinding.inflate(layoutInflater)
        setContentView(binding.root)
        session = SessionStore(this)

        val url = intent.getStringExtra(EXTRA_URL) ?: run {
            finish()
            return
        }
        if (!isTrustedUrl(url)) {
            finish()
            return
        }

        configureWebView()
        binding.webView.loadUrl(url)
    }

    private fun configureWebView() {
        with(binding.webView.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true
            mediaPlaybackRequiresUserGesture = false
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            allowFileAccess = false
            allowContentAccess = false
            javaScriptCanOpenWindowsAutomatically = false
            setSupportMultipleWindows(false)
            userAgentString = "$userAgentString SalaReuniaoAndroid/1.0.0"
        }

        binding.webView.webViewClient = object : WebViewClient() {
            override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                binding.progress.visibility = View.VISIBLE
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                binding.progress.visibility = View.GONE
            }

            override fun shouldOverrideUrlLoading(
                view: WebView?,
                request: android.webkit.WebResourceRequest
            ): Boolean {
                val url = request.url.toString()
                return if (isTrustedUrl(url)) {
                    false
                } else {
                    startActivity(Intent(Intent.ACTION_VIEW, request.url))
                    true
                }
            }
        }

        binding.webView.webChromeClient = object : WebChromeClient() {
            override fun onPermissionRequest(request: PermissionRequest) {
                runOnUiThread { handleWebPermission(request) }
            }

            override fun onPermissionRequestCanceled(request: PermissionRequest) {
                if (pendingWebPermission == request) pendingWebPermission = null
            }

            override fun onShowCustomView(view: View, callback: CustomViewCallback) {
                if (customView != null) {
                    callback.onCustomViewHidden()
                    return
                }
                customView = view
                customViewCallback = callback
                binding.webView.visibility = View.GONE
                binding.rootMeeting.addView(
                    view,
                    ViewGroup.LayoutParams(
                        ViewGroup.LayoutParams.MATCH_PARENT,
                        ViewGroup.LayoutParams.MATCH_PARENT
                    )
                )
            }

            override fun onHideCustomView() {
                val view = customView ?: return
                binding.rootMeeting.removeView(view)
                customView = null
                binding.webView.visibility = View.VISIBLE
                customViewCallback?.onCustomViewHidden()
                customViewCallback = null
            }
        }
    }

    private fun handleWebPermission(request: PermissionRequest) {
        if (!isTrustedOrigin(request.origin)) {
            request.deny()
            return
        }

        val wantsVideo = request.resources.contains(PermissionRequest.RESOURCE_VIDEO_CAPTURE)
        val wantsAudio = request.resources.contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE)
        val androidPermissions = mutableListOf<String>()

        if (
            wantsVideo &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            androidPermissions += Manifest.permission.CAMERA
        }

        if (
            wantsAudio &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            androidPermissions += Manifest.permission.RECORD_AUDIO
        }

        if (androidPermissions.isEmpty()) {
            grantMediaResources(request)
        } else {
            pendingWebPermission?.deny()
            pendingWebPermission = request
            ActivityCompat.requestPermissions(
                this,
                androidPermissions.toTypedArray(),
                MEDIA_PERMISSION_CODE
            )
        }
    }

    private fun grantMediaResources(request: PermissionRequest) {
        val allowed = request.resources.filter {
            it == PermissionRequest.RESOURCE_VIDEO_CAPTURE ||
                it == PermissionRequest.RESOURCE_AUDIO_CAPTURE
        }.toTypedArray()

        if (allowed.isEmpty()) request.deny() else request.grant(allowed)
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode != MEDIA_PERMISSION_CODE) return

        val request = pendingWebPermission
        pendingWebPermission = null
        if (request == null) return

        val allGranted =
            grantResults.isNotEmpty() &&
            grantResults.all { it == PackageManager.PERMISSION_GRANTED }

        if (allGranted && isTrustedOrigin(request.origin)) {
            grantMediaResources(request)
        } else {
            request.deny()
        }
    }

    private fun isTrustedOrigin(origin: Uri): Boolean {
        val trusted = Uri.parse(session.webBase)
        val hostAllowed =
            origin.host.equals(trusted.host, ignoreCase = true) ||
            origin.host.equals(BuildConfig.APP_HOST, ignoreCase = true)
        return origin.scheme == "https" &&
            hostAllowed &&
            effectivePort(origin) == effectivePort(trusted)
    }

    private fun isTrustedUrl(url: String): Boolean {
        return try {
            isTrustedOrigin(Uri.parse(url))
        } catch (_: Exception) {
            false
        }
    }

    private fun effectivePort(uri: Uri): Int {
        return if (uri.port != -1) uri.port else if (uri.scheme == "https") 443 else 80
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        when {
            customView != null -> binding.webView.webChromeClient?.onHideCustomView()
            binding.webView.canGoBack() -> binding.webView.goBack()
            else -> super.onBackPressed()
        }
    }

    override fun onDestroy() {
        pendingWebPermission?.deny()
        pendingWebPermission = null

        binding.webView.apply {
            stopLoading()
            loadUrl("about:blank")
            clearHistory()
            removeAllViews()
            destroy()
        }
        super.onDestroy()
    }
}
