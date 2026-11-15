{{-- Study-only, removable: see app/Study/README.md. One titled table of PlanPreview::tables(). --}}
<flux:card class="mt-6 overflow-x-auto">
    <flux:heading>{{ $table['title'] }}</flux:heading>
    <flux:table class="mt-2">
        <flux:table.columns>
            @foreach ($table['columns'] as $index => $column)
                <flux:table.column :align="$index === 0 ? 'start' : 'end'">{{ $column }}</flux:table.column>
            @endforeach
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($table['rows'] as $row)
                <flux:table.row>
                    @foreach ($row as $index => $cell)
                        <flux:table.cell :align="$index === 0 ? 'start' : 'end'" class="tabular-nums">{{ $cell }}</flux:table.cell>
                    @endforeach
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</flux:card>
