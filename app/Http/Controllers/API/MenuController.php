<?php
// app/Http/Controllers/API/MenuController.php
namespace App\Http\Controllers\API;

use App\Models\Menu;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class MenuController extends Controller
{
    public function getUserMenu()
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([]);
        }

        // Get all active menus
        $menus = Menu::with(['children' => function ($query) use ($user) {
            $query->active()->orderBy('order');
        }])
            ->mainMenu()
            ->active()
            ->orderBy('order')
            ->get();

        // Filter menus based on user permissions and conditions
        $filteredMenus = $menus->filter(function ($menu) use ($user) {
            return $this->userHasAccess($menu, $user);
        })->values();

        return response()->json($this->formatMenuForFrontend($filteredMenus, $user));
    }

    private function userHasAccess($menu, $user)
    {
        // Check permission if set
        if ($menu->permission_name && !$user->can($menu->permission_name)) {
            return false;
        }

        // Check conditions if set
        if ($menu->conditions && !$this->checkConditions($menu->conditions, $user)) {
            return false;
        }

        // For parent menus, check if any children are accessible
        if ($menu->children->isNotEmpty()) {
            $accessibleChildren = $menu->children->filter(function ($child) use ($user) {
                return $this->userHasAccess($child, $user);
            });

            return $accessibleChildren->isNotEmpty();
        }

        return true;
    }

    private function checkConditions($conditions, $user)
    {
        // Example condition: {"VIPAgency": 1}
        foreach ($conditions as $key => $value) {
            if ($user->incorporation->$key != $value) {
                return false;
            }
        }
        return true;
    }

    private function formatMenuForFrontend($menus, $user)
    {
        return $menus->map(function ($menu) use ($user) {
            $formatted = [
                'id' => $menu->id,
                'title' => $menu->title,
                'icon' => $menu->icon,
                'match' => $menu->match,
                'link' => $this->generateLink($menu)
            ];

            // Filter and format children
            if ($menu->children->isNotEmpty()) {
                $accessibleChildren = $menu->children->filter(function ($child) use ($user) {
                    return $this->userHasAccess($child, $user);
                });

                if ($accessibleChildren->isNotEmpty()) {
                    $formatted['submenu'] = $this->formatMenuForFrontend($accessibleChildren, $user);
                }
            }

            return $formatted;
        });
    }

    private function generateLink($menu)
    {
        if ($menu->route_name) {
            return route($menu->route_name);
        }

        if ($menu->url) {
            return $menu->url;
        }

        return '#';
    }

    // Admin methods for managing menus
    public function index()
    {
        $menus = Menu::with('children')
            ->mainMenu()
            ->orderBy('order')
            ->get();

        return response()->json($menus);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'match' => 'nullable|string|max:255',
            'route_name' => 'nullable|string|max:255',
            'url' => 'nullable|string|max:255',
            'parent_id' => 'nullable|exists:menus,id',
            'order' => 'integer|min:0',
            'permission_name' => 'nullable|string|max:255|exists:permissions,name',
            'conditions' => 'nullable|array',
        ]);

        $menu = Menu::create($validated);

        return response()->json($menu->load('children'), 201);
    }

    public function update(Request $request, Menu $menu)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'match' => 'nullable|string|max:255',
            'route_name' => 'nullable|string|max:255',
            'url' => 'nullable|string|max:255',
            'parent_id' => 'nullable|exists:menus,id',
            'order' => 'integer|min:0',
            'is_active' => 'boolean',
            'permission_name' => 'nullable|string|max:255|exists:permissions,name',
            'conditions' => 'nullable|array',
        ]);

        $menu->update($validated);

        return response()->json($menu->load('children'));
    }

    public function destroy(Menu $menu)
    {
        // Prevent deletion if menu has children
        if ($menu->children()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete menu with children. Delete children first.'
            ], 422);
        }

        $menu->delete();
        return response()->json(null, 204);
    }
}
