#define UNICODE
#define _UNICODE
#include <windows.h>
#include <psapi.h>
#include <uiautomation.h>
#include <iostream>
#include <fstream>
#include <vector>
#include <string>
#include <sstream>
#include <iomanip>

struct ProbeTargetWindow {
    HWND hwnd;
    DWORD pid;
    std::wstring title;
    std::wstring className;
    RECT rect;
    std::wstring processPath;
};

std::vector<ProbeTargetWindow> g_capcutWindows;

BOOL CALLBACK EnumWindowsProc(HWND hwnd, LPARAM lParam) {
    if (!IsWindowVisible(hwnd)) return TRUE;

    DWORD pid = 0;
    GetWindowThreadProcessId(hwnd, &pid);
    if (pid == 0) return TRUE;

    HANDLE hProc = OpenProcess(PROCESS_QUERY_INFORMATION | PROCESS_VM_READ, FALSE, pid);
    if (!hProc) return TRUE;

    wchar_t exePath[MAX_PATH] = {0};
    GetModuleFileNameExW(hProc, NULL, exePath, MAX_PATH);
    CloseHandle(hProc);

    std::wstring pathStr(exePath);
    if (pathStr.find(L"CapCut.exe") != std::wstring::npos || pathStr.find(L"capcut.exe") != std::wstring::npos) {
        wchar_t title[512] = {0};
        GetWindowTextW(hwnd, title, 512);

        wchar_t cls[256] = {0};
        GetClassNameW(hwnd, cls, 256);

        RECT r = {0};
        GetWindowRect(hwnd, &r);

        ProbeTargetWindow tw;
        tw.hwnd = hwnd;
        tw.pid = pid;
        tw.title = title;
        tw.className = cls;
        tw.rect = r;
        tw.processPath = pathStr;
        g_capcutWindows.push_back(tw);
    }
    return TRUE;
}

std::string WStringToString(const std::wstring& wstr) {
    if (wstr.empty()) return std::string();
    int sizeNeeded = WideCharToMultiByte(CP_UTF8, 0, &wstr[0], (int)wstr.size(), NULL, 0, NULL, NULL);
    std::string strTo(sizeNeeded, 0);
    WideCharToMultiByte(CP_UTF8, 0, &wstr[0], (int)wstr.size(), &strTo[0], sizeNeeded, NULL, NULL);
    return strTo;
}

void InspectElementRecursive(IUIAutomation* pAutomation, IUIAutomationElement* pElement, std::ostream& out, int depth, int maxDepth = 4) {
    if (!pElement || depth > maxDepth) return;

    BSTR name = NULL;
    BSTR autoId = NULL;
    BSTR clsName = NULL;
    CONTROLTYPEID ctrlType = 0;
    RECT rect = {0};

    pElement->get_CurrentName(&name);
    pElement->get_CurrentAutomationId(&autoId);
    pElement->get_CurrentClassName(&clsName);
    pElement->get_CurrentControlType(&ctrlType);
    pElement->get_CurrentBoundingRectangle(&rect);

    std::string indent(depth * 2, ' ');
    std::wstring wsName = name ? name : L"";
    std::wstring wsId = autoId ? autoId : L"";
    std::wstring wsCls = clsName ? clsName : L"";

    out << indent << "{\n";
    out << indent << "  \"type_id\": " << ctrlType << ",\n";
    out << indent << "  \"name\": \"" << WStringToString(wsName) << "\",\n";
    out << indent << "  \"automation_id\": \"" << WStringToString(wsId) << "\",\n";
    out << indent << "  \"class_name\": \"" << WStringToString(wsCls) << "\",\n";
    out << indent << "  \"bounds\": {\"left\":" << rect.left << ",\"top\":" << rect.top << ",\"right\":" << rect.right << ",\"bottom\":" << rect.bottom << "}\n";
    out << indent << "}";

    if (name) SysFreeString(name);
    if (autoId) SysFreeString(autoId);
    if (clsName) SysFreeString(clsName);

    IUIAutomationTreeWalker* pWalker = NULL;
    pAutomation->get_ControlViewWalker(&pWalker);
    if (pWalker) {
        IUIAutomationElement* pChild = NULL;
        pWalker->GetFirstChildElement(pElement, &pChild);
        while (pChild) {
            out << ",\n";
            InspectElementRecursive(pAutomation, pChild, out, depth + 1, maxDepth);
            IUIAutomationElement* pNext = NULL;
            pWalker->GetNextSiblingElement(pChild, &pNext);
            pChild->Release();
            pChild = pNext;
        }
        pWalker->Release();
    }
}

int main(int argc, char* argv[]) {
    for (int i = 1; i < argc; ++i) {
        std::string arg = argv[i];
        if (arg == "--help" || arg == "-h") {
            std::cout << "Usage: CapCutUiProbe.exe [options]\n";
            std::cout << "Options:\n";
            std::cout << "  --self-test       Perform safe COM/UIA initialization test and return JSON\n";
            std::cout << "  --json            Output probe result directly to stdout\n";
            std::cout << "  --help, -h        Show this help message\n";
            return 0;
        }
        if (arg == "--self-test") {
            HRESULT hrCo = CoInitialize(NULL);
            IUIAutomation* pTestAuto = NULL;
            HRESULT hrInstance = CoCreateInstance(CLSID_CUIAutomation, NULL, CLSCTX_INPROC_SERVER, IID_IUIAutomation, (void**)&pTestAuto);
            bool uiaOk = SUCCEEDED(hrInstance) && (pTestAuto != NULL);
            if (pTestAuto) pTestAuto->Release();
            CoUninitialize();

            std::cout << "{\"self_test\": true, \"uia_initialized\": " << (uiaOk ? "true" : "false")
                      << ", \"hresult\": " << hrInstance
                      << ", \"target_capcut_version\": \"9.3.0.3970\"}\n";
            return uiaOk ? 0 : 1;
        }
    }

    std::cout << "========================================================\n";
    std::cout << "   2TOOLNE CAPCUT UI AUTOMATION CAPABILITY PROBE v2.0    \n";
    std::cout << "   Target CapCut Desktop: 9.3.0.3970                     \n";
    std::cout << "========================================================\n\n";

    std::cout << "[1/4] Scanning running processes for CapCut.exe...\n";
    EnumWindows(EnumWindowsProc, 0);

    if (g_capcutWindows.empty()) {
        std::cout << "[-] CapCut.exe window not found. CapCut may not be running.\n";
        std::cout << "    Please start CapCut Desktop and open a project, then rerun this probe.\n\n";
    } else {
        std::cout << "[+] Found " << g_capcutWindows.size() << " window(s) associated with CapCut:\n";
        for (size_t i = 0; i < g_capcutWindows.size(); ++i) {
            std::cout << "    [" << i << "] HWND: " << g_capcutWindows[i].hwnd
                      << " | Title: \"" << WStringToString(g_capcutWindows[i].title) << "\""
                      << " | Class: \"" << WStringToString(g_capcutWindows[i].className) << "\""
                      << " | PID: " << g_capcutWindows[i].pid << "\n";
        }
    }

    std::cout << "\n[2/4] Initializing Windows UI Automation COM Client...\n";
    HRESULT hr = CoInitialize(NULL);
    IUIAutomation* pAutomation = NULL;
    hr = CoCreateInstance(CLSID_CUIAutomation, NULL, CLSCTX_INPROC_SERVER, IID_IUIAutomation, (void**)&pAutomation);

    std::stringstream reportJson;
    reportJson << "{\n";
    reportJson << "  \"probe_version\": \"2.0.0\",\n";
    reportJson << "  \"timestamp\": \"" << __DATE__ << " " << __TIME__ << "\",\n";
    reportJson << "  \"target_capcut_version\": \"9.3.0.3970\",\n";
    reportJson << "  \"windows_found\": " << g_capcutWindows.size() << ",\n";
    reportJson << "  \"windows\": [\n";

    for (size_t i = 0; i < g_capcutWindows.size(); ++i) {
        reportJson << "    {\n";
        reportJson << "      \"hwnd\": " << (unsigned long long)g_capcutWindows[i].hwnd << ",\n";
        reportJson << "      \"pid\": " << g_capcutWindows[i].pid << ",\n";
        reportJson << "      \"title\": \"" << WStringToString(g_capcutWindows[i].title) << "\",\n";
        reportJson << "      \"class\": \"" << WStringToString(g_capcutWindows[i].className) << "\",\n";
        reportJson << "      \"path\": \"" << WStringToString(g_capcutWindows[i].processPath) << "\",\n";
        reportJson << "      \"rect\": {\"left\":" << g_capcutWindows[i].rect.left << ",\"top\":" << g_capcutWindows[i].rect.top << ",\"right\":" << g_capcutWindows[i].rect.right << ",\"bottom\":" << g_capcutWindows[i].rect.bottom << "},\n";
        reportJson << "      \"uia_elements\": [\n";

        if (pAutomation) {
            IUIAutomationElement* pRoot = NULL;
            pAutomation->ElementFromHandle(g_capcutWindows[i].hwnd, &pRoot);
            if (pRoot) {
                InspectElementRecursive(pAutomation, pRoot, reportJson, 0, 4);
                pRoot->Release();
            }
        }

        reportJson << "\n      ]\n";
        reportJson << "    }" << (i + 1 < g_capcutWindows.size() ? ",\n" : "\n");
    }

    reportJson << "  ]\n";
    reportJson << "}\n";

    if (pAutomation) pAutomation->Release();
    CoUninitialize();

    std::cout << "[3/4] Writing probe report to capcut_probe_report.json...\n";
    std::ofstream outFile("capcut_probe_report.json");
    if (outFile.is_open()) {
        outFile << reportJson.str();
        outFile.close();
        std::cout << "[+] Saved capcut_probe_report.json successfully.\n";
    }

    std::cout << "[4/4] Probe execution finished. Status: READY_FOR_RENDER_PROFILE\n";
    return 0;
}
